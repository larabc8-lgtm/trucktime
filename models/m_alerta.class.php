<?php
class Alerta {
    private $conn;
    private $table = "alertas";

    public $id_alerta;
    public $tipo_alerta;
    public $mensaje;
    public $fecha_hora;
    public $leida;
    public $id_jornada;

    public function __construct($conexion) {
        $this->conn = $conexion;
    }

    // Crear una nueva alerta
    public function crearAlerta() {
        $query = "INSERT INTO " . $this->table . "
                      (id_jornada, tipo_alerta, mensaje)
                  VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iss", $this->id_jornada, $this->tipo_alerta, $this->mensaje);

        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return false;
    }

    // Obtener todas las alertas de una jornada
    public function getAlertasJornada($id_jornada) {
        $query = "SELECT id_alerta, tipo_alerta, mensaje, fecha_hora, leida
                  FROM " . $this->table . "
                  WHERE id_jornada = ?
                  ORDER BY fecha_hora DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_jornada);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Obtener alertas no leídas de un usuario (de todas sus jornadas)
    public function getAlertasNoLeidas($id_usuario) {
        $query = "SELECT a.id_alerta, a.tipo_alerta, a.mensaje, a.fecha_hora, a.id_jornada
                  FROM " . $this->table . " a
                  INNER JOIN jornadas j ON a.id_jornada = j.id_jornada
                  WHERE j.id_usuario = ? AND a.leida = 0
                  ORDER BY a.fecha_hora DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Marcar una alerta como leída
    public function marcarLeida($id_alerta) {
        $query = "UPDATE " . $this->table . " SET leida = 1 WHERE id_alerta = ?";
        $stmt  = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_alerta);
        return $stmt->execute();
    }

    // Marcar todas las alertas de una jornada como leídas
    public function marcarTodasLeidas($id_jornada) {
        $query = "UPDATE " . $this->table . " SET leida = 1 WHERE id_jornada = ?";
        $stmt  = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_jornada);
        return $stmt->execute();
    }

    // ---------------------------------------------------------------
    // Comprobar si ya existe una alerta del mismo tipo en esta jornada
    // Evita duplicar la misma alerta si se llama varias veces
    // ---------------------------------------------------------------
    private function _yaExisteAlerta($id_jornada, $tipo_alerta) {
        $query = "SELECT id_alerta FROM " . $this->table . "
                  WHERE id_jornada = ? AND tipo_alerta = ?
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("is", $id_jornada, $tipo_alerta);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    // ---------------------------------------------------------------
    // Calcula los minutos de conducción CONTINUA (sin pausas entre medias)
    // Es lo que realmente exige el CE 561/2006: no más de 4.5h SIN pausa
    // ---------------------------------------------------------------
    private function _getConduccionContinua($id_jornada) {
        $registroModel = new RegistroActividad($this->conn);
        $datos = $registroModel->getMinutosConduccion($id_jornada);
        
        // Devolvemos los minutos con precisión decimal (segundos / 60)
        return $datos['conduccion_activa'] / 60;
    }

    // ---------------------------------------------------------------
    // Calcula los minutos entre la última jornada cerrada y la actual
    // Para comprobar si el descanso entre jornadas fue suficiente (min 11h)
    // ---------------------------------------------------------------
    private function _getDescansoEntreJornadas($id_jornada) {
        $query = "SELECT j.id_usuario, j.fecha_inicio,
                         (SELECT fecha_fin FROM jornadas
                          WHERE id_usuario = j.id_usuario
                            AND estado = 'cerrada'
                            AND fecha_fin < j.fecha_inicio
                          ORDER BY fecha_fin DESC
                          LIMIT 1) AS fin_jornada_anterior
                  FROM jornadas j
                  WHERE j.id_jornada = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_jornada);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();

        if (!$fila || !$fila['fin_jornada_anterior']) {
            return null;
        }
        $minutosDescanso = (int) ((
            strtotime($fila['fecha_inicio']) -
            strtotime($fila['fin_jornada_anterior'])
        ) / 60);

        return $minutosDescanso;
    }

    // ---------------------------------------------------------------
    // Calcula los minutos de conducción totales en la semana actual
    // Límite CE 561/2006: máx 56h semanales (3360 min)
    // ---------------------------------------------------------------
    private function _getConduccionSemanal($id_usuario) {
        $query = "SELECT COALESCE(SUM(
                      TIMESTAMPDIFF(MINUTE, ra.hora_inicio, ra.hora_fin)
                  ), 0) AS total
                  FROM registro_actividad ra
                  INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
                  WHERE j.id_usuario = ?
                    AND ra.tipo_actividad = 'conduccion'
                    AND ra.hora_fin IS NOT NULL
                    AND YEARWEEK(ra.hora_inicio, 1) = YEARWEEK(NOW(), 1)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return (int) $fila['total'];
    }

    //Buscamos jornadas de esta semana donde la conducción superó los 540 min (9h)
    //Se puede superar dos veces en la semana hasta 10h.
    private function _getAmpliacionesUsadasEstaSemana($id_usuario) {
        $query = "SELECT COUNT(*) as total 
                FROM jornadas 
                WHERE id_usuario = ? 
                    AND duracion_conduccion_total > 540 
                    AND estado = 'cerrada'
                    AND YEARWEEK(fecha_inicio, 1) = YEARWEEK(NOW(), 1)";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        return (int) $res['total'];
    }

    // Comprobar todas las alertas mientras jornada está activa
    public function comprobarAlertas($id_jornada, $id_usuario) {
        $alertasGeneradas = [];

        // --- CONDUCCIÓN CONTINUA ---
        $conduccionContinua = $this->_getConduccionContinua($id_jornada);

        // Aviso preventivo: 15 min antes de la pausa (4h 15min = 255 min)
        if ($conduccionContinua >= 255 && $conduccionContinua < 270) {
            if (!$this->_yaExisteAlerta($id_jornada, 'pausa_obligatoria')) {
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'pausa_obligatoria';
                $this->mensaje     = 'Atención: En 15 minutos debes realizar una pausa obligatoria de 45 min.';
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        // Pausa obligatoria superada: 4h 30min = 270 min
        if ($conduccionContinua >= 270) {
            if (!$this->_yaExisteAlerta($id_jornada, 'pausa_obligatoria')) {
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'pausa_obligatoria';
                $this->mensaje     = 'Has superado 4h 30min de conducción continua. Para inmediatamente y descansa 45 min.';
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        // --- CONDUCCIÓN MÁXIMA DIARIA ---
        $registroModel = new RegistroActividad($this->conn);
        $datos = $registroModel->getMinutosConduccion($id_jornada);
        $conduccionDiaria = $datos['conduccion_total'] / 60;

        // Aviso preventivo: 30 min antes del límite (8h 30min = 510 min)
        if ($conduccionDiaria >= 510 && $conduccionDiaria < 540) {
            if (!$this->_yaExisteAlerta($id_jornada, 'conduccion_maxima_diaria')) {
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'conduccion_maxima_diaria';
                $this->mensaje     = 'Aviso: Te quedan 30 minutos de conducción diaria permitida (límite 9h).';
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        // Límite diario alcanzado: 9h = 540 min
        if ($conduccionDiaria >= 540) {
            if (!$this->_yaExisteAlerta($id_jornada, 'conduccion_maxima_diaria')) {
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'conduccion_maxima_diaria';
                $this->mensaje     = 'Has alcanzado el límite de 9 horas de conducción diaria. Detén el vehículo.';
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        // --- DESCANSO ENTRE JORNADAS ---
        $minutosDescanso = $this->_getDescansoEntreJornadas($id_jornada);
        // 11h = 660 min mínimo de descanso entre jornadas
        if ($minutosDescanso !== null && $minutosDescanso < 660) {
            if (!$this->_yaExisteAlerta($id_jornada, 'descanso_insuficiente')) {
                $horas  = intdiv($minutosDescanso, 60);
                $minutos = $minutosDescanso % 60;
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'descanso_insuficiente';
                $this->mensaje     = "El descanso entre jornadas fue de {$horas}h {$minutos}min. El mínimo obligatorio es 11h.";
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        // --- CONDUCCIÓN SEMANAL ---
        $conduccionSemanal = $this->_getConduccionSemanal($id_usuario);
        // 56h = 3360 min semanales máximo
        if ($conduccionSemanal >= 3360) {
            if (!$this->_yaExisteAlerta($id_jornada, 'conduccion_semanal')) {
                $this->id_jornada  = $id_jornada;
                $this->tipo_alerta = 'conduccion_semanal';
                $this->mensaje     = 'Has superado el límite de 56 horas de conducción semanal.';
                if ($this->crearAlerta()) $alertasGeneradas[] = $this->tipo_alerta;
            }
        }

        return $alertasGeneradas;
    }
}
?>
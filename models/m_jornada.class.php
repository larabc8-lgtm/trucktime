<?php
class Jornada {
    private $conn;
    private $table = "jornadas";

    public $id_jornada;
    public $fecha_inicio;
    public $fecha_fin;
    public $duracion_conduccion_total;
    public $duracion_descanso_total;
    public $estado;
    public $tipo_descanso;
    public $descansos_reducidos_semana;
    public $id_usuario;
    
    public function __construct($conexion) {
        $this->conn = $conexion;
    }

    public function abrirJornada() {
        $check = $this->conn->prepare(
            "SELECT id_jornada FROM " . $this->table . "
             WHERE id_usuario = ? AND estado = 'activa' LIMIT 1"
        );
        $check->bind_param("i", $this->id_usuario);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            return "Ya tienes una jornada activa";
        }

        // Buscar la jornada anterior cerrada
        $stmtAnterior = $this->conn->prepare(
            "SELECT id_jornada, fecha_fin, descansos_reducidos_semana
             FROM " . $this->table . "
             WHERE id_usuario = ? AND estado = 'cerrada'
             ORDER BY fecha_fin DESC LIMIT 1"
        );
        $stmtAnterior->bind_param("i", $this->id_usuario);
        $stmtAnterior->execute();
        $anterior = $stmtAnterior->get_result()->fetch_assoc();
 
        $tipoDescanso            = null;
        $descansosReducidosSemana = 0;
        $fechaInicio             = date('Y-m-d H:i:s');
 
        if ($anterior && $anterior['fecha_fin']) {
            $minutosDescanso = (int)(
                (strtotime($fechaInicio) - strtotime($anterior['fecha_fin'])) / 60
            );
            $descansosReducidosSemana = (int)$anterior['descansos_reducidos_semana'];
 
            // Clasificar el descanso
            if ($minutosDescanso >= 660) {
                // >= 11h → descanso normal
                // Comprobar si fue fraccionado (3h + 9h)
                $esFraccionado = $this->_comprobarFraccionado($anterior['id_jornada'], $anterior['fecha_fin'], $fechaInicio);
                $tipoDescanso = $esFraccionado ? 'fraccionado' : 'normal';
                $descansosReducidosSemana = 0;
                // Si fue normal o fraccionado, reset del contador de reducidos
                // Solo reseteamos si fue suficientemente largo (no reducido)
            } elseif ($minutosDescanso >= 540) {
                // >= 9h y < 11h → descanso reducido
                $tipoDescanso = 'reducido';
                $descansosReducidosSemana++;
            } else {
                // < 9h → insuficiente
                $tipoDescanso = 'insuficiente';
                // No incrementamos reducidos, es directamente insuficiente
            }
            // Validación extra: Alerta si supera los 3 permitidos
            if ($descansosReducidosSemana > 3) {
            }
        }
 
        // Insertar nueva jornada
        $query = "INSERT INTO " . $this->table . "
                      (id_usuario, fecha_inicio, estado,
                       tipo_descanso, descansos_reducidos_semana)
                  VALUES (?, ?, 'activa', ?, ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("issi",
            $this->id_usuario,
            $fechaInicio,
            $tipoDescanso,
            $descansosReducidosSemana
        );
 
        if ($stmt->execute()) {
            return [
                'id_jornada'               => $this->conn->insert_id,
                'tipo_descanso'            => $tipoDescanso,
                'descansos_reducidos_semana' => $descansosReducidosSemana,
            ];
        }
        return false;
    }
 
    // ---------------------------------------------------------------
    // Comprueba si el descanso entre jornadas fue fraccionado (3h + 9h)
    // Busca dos bloques de descanso en registro_actividad entre las dos jornadas
    // ---------------------------------------------------------------
    private function _comprobarFraccionado($idJornadaAnterior, $finAnterior, $inicioActual) {
        $query = "SELECT hora_inicio, hora_fin,
                         TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin) AS duracion
                  FROM registro_actividad
                  WHERE id_jornada = ?
                    AND tipo_actividad = 'descanso'
                    AND hora_inicio >= ?
                    AND hora_fin <= ?
                    AND hora_fin IS NOT NULL
                  ORDER BY hora_inicio ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iss", $idJornadaAnterior, $finAnterior, $inicioActual);
        $stmt->execute();
        $bloques = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
 
        if (count($bloques) < 2) return false;
 
        // Buscar combinación válida: un bloque >= 180 min (3h) y otro >= 540 min (9h)
        foreach ($bloques as $b1) {
            foreach ($bloques as $b2) {
                if ($b1 === $b2) continue;
                if (
                    (int)$b1['duracion'] >= 180 && (int)$b2['duracion'] >= 540 ||
                    (int)$b1['duracion'] >= 540 && (int)$b2['duracion'] >= 180
                ) {
                    return true;
                }
            }
        }
        return false;
    }

    public function cerrarJornada($id_jornada) {
        try {
            //Cerrar la última actividad abierta
            $sqlActividad = "UPDATE registro_actividad
                             SET hora_fin = NOW()
                             WHERE id_jornada = ? AND hora_fin IS NULL";
            $stmtAct = $this->conn->prepare($sqlActividad);
            $stmtAct->bind_param("i", $id_jornada);
            $stmtAct->execute();

            //Calcular minutos de conducción
            $duracionConduccion = $this->_calcularMinutos(
                $id_jornada, 'conduccion'
            );

            //Calcular minutos de descanso (incluye pausas)
            $duracionDescanso = $this->_calcularMinutosMultiple(
                $id_jornada, ['descanso', 'pausa']
            );

            //Cerrar la jornada guardando ambas duraciones
            $sqlJornada = "UPDATE " . $this->table . "
                           SET fecha_fin = NOW(),
                               estado = 'cerrada',
                               duracion_conduccion_total = ?,
                               duracion_descanso_total = ?
                           WHERE id_jornada = ? AND estado = 'activa'";
            $stmtJor = $this->conn->prepare($sqlJornada);
            $stmtJor->bind_param(
                "iii",
                $duracionConduccion,
                $duracionDescanso,
                $id_jornada
            );

            return $stmtJor->execute();

        } catch (Exception $e) {
            error_log("Error al cerrar jornada: " . $e->getMessage());
            return false;
        }
    }

    // Calcular minutos de un tipo de actividad en una jornada
    private function _calcularMinutos($id_jornada, $tipo) {
        $sql = "SELECT COALESCE(SUM(
                    TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin)
                ), 0) AS total
                FROM registro_actividad
                WHERE id_jornada = ?
                  AND tipo_actividad = ?
                  AND hora_fin IS NOT NULL";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("is", $id_jornada, $tipo);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return (int) $fila['total'];
    }

    // Calcular minutos sumando varios tipos de actividad
    private function _calcularMinutosMultiple($id_jornada, array $tipos) {
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));
        $sql = "SELECT COALESCE(SUM(
                    TIMESTAMPDIFF(MINUTE, hora_inicio, hora_fin)
                ), 0) AS total
                FROM registro_actividad
                WHERE id_jornada = ?
                  AND tipo_actividad IN ($placeholders)
                  AND hora_fin IS NOT NULL";

        $stmt = $this->conn->prepare($sql);

        // Construimos el bind dinámico: "i" + una "s" por cada tipo
        $types = "i" . str_repeat("s", count($tipos));
        $params = array_merge([$id_jornada], $tipos);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        return (int) $fila['total'];
    }

    // Obtener todas las jornadas de un usuario con duraciones y totales
    public function getJornadasUsuario($id_usuario) {
        $query = "SELECT id_jornada, fecha_inicio, fecha_fin,
                         duracion_conduccion_total,
                         duracion_descanso_total,
                         TIMESTAMPDIFF(MINUTE, fecha_inicio,
                             COALESCE(fecha_fin, NOW())) AS duracion_jornada_total,
                         estado, tipo_descanso, descansos_reducidos_semana
                  FROM " . $this->table . "
                  WHERE id_usuario = ?
                  ORDER BY fecha_inicio DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // Obtener la jornada activa de un usuario
    public function getJornadaActiva($id_usuario) {
        $query = "SELECT id_jornada, fecha_inicio,
                         duracion_conduccion_total,
                         duracion_descanso_total
                  FROM " . $this->table . "
                  WHERE id_usuario = ? AND estado = 'activa'
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    // ---------------------------------------------------------------
    // Busca el inicio de la semana real del conductor
    // = fin del último descanso de al menos 45h (2700 min)
    // Si no encuentra ninguno, usa el lunes de la semana actual
    // ---------------------------------------------------------------
    private function _getInicioSemanaReal($id_usuario) {
        // Buscamos en registro_actividad descansos de >= 45h seguidas
        $query = "SELECT ra.hora_fin
                  FROM registro_actividad ra
                  INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
                  WHERE j.id_usuario = ?
                    AND ra.tipo_actividad IN ('descanso', 'pausa')
                    AND ra.hora_fin IS NOT NULL
                    AND TIMESTAMPDIFF(MINUTE, ra.hora_inicio, ra.hora_fin) >= 2700
                  ORDER BY ra.hora_fin DESC
                  LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_usuario);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
 
        if ($fila && $fila['hora_fin']) {
            return $fila['hora_fin'];
        }
 
        // Si no hay descanso largo registrado, usamos el lunes a las 00:00
        return date('Y-m-d 00:00:00', strtotime('monday this week'));
    }
 
    // Devuelve un resumen de conducción semanal y mensual
    public function getResumenConduccion($id_usuario) {
        $inicioSemana = $this->_getInicioSemanaReal($id_usuario);
 
        // Minutos de conducción desde el inicio de semana real
        $querySemana = "SELECT COALESCE(SUM(
                            TIMESTAMPDIFF(MINUTE, ra.hora_inicio,
                                COALESCE(ra.hora_fin, NOW()))
                        ), 0) AS total
                        FROM registro_actividad ra
                        INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
                        WHERE j.id_usuario = ?
                          AND ra.tipo_actividad = 'conduccion'
                          AND ra.hora_inicio >= ?";
        $stmt = $this->conn->prepare($querySemana);
        $stmt->bind_param("is", $id_usuario, $inicioSemana);
        $stmt->execute();
        $minSemana = (int) $stmt->get_result()->fetch_assoc()['total'];
 
        // Minutos de conducción del mes natural
        $inicioMes = date('Y-m-01 00:00:00');
        $queryMes  = "SELECT COALESCE(SUM(
                          TIMESTAMPDIFF(MINUTE, ra.hora_inicio,
                              COALESCE(ra.hora_fin, NOW()))
                      ), 0) AS total
                      FROM registro_actividad ra
                      INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
                      WHERE j.id_usuario = ?
                        AND ra.tipo_actividad = 'conduccion'
                        AND ra.hora_inicio >= ?";
        $stmt2 = $this->conn->prepare($queryMes);
        $stmt2->bind_param("is", $id_usuario, $inicioMes);
        $stmt2->execute();
        $minMes = (int) $stmt2->get_result()->fetch_assoc()['total'];
 
        // Descansos reducidos usados en la semana actual
        $queryReducidos = "SELECT COALESCE(MAX(descansos_reducidos_semana), 0) AS total
                           FROM " . $this->table . "
                           WHERE id_usuario = ?
                             AND fecha_inicio >= ?
                           ORDER BY fecha_inicio DESC LIMIT 1";
        $stmt3 = $this->conn->prepare($queryReducidos);
        $stmt3->bind_param("is", $id_usuario, $inicioSemana);
        $stmt3->execute();
        $descansosReducidos = (int) $stmt3->get_result()->fetch_assoc()['total'];
 
        return [
            'conduccion_semana'        => $minSemana,
            'conduccion_mes'           => $minMes,
            'inicio_semana'            => $inicioSemana,
            'descansos_reducidos'      => $descansosReducidos,
            'descansos_reducidos_max'  => 3,
        ];
    }
}
?>
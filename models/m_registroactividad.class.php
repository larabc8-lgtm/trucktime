<?php
class RegistroActividad {
    private $conn;
    private $table = "registro_actividad";

    public $id_registro;
    public $tipo_actividad;
    public $hora_inicio;
    public $hora_fin;
    public $latitud;
    public $longitud;
    public $es_descanso_semanal;
    public $id_jornada;

    public function __construct($conexion) {
        $this->conn = $conexion;
    }

// Iniciar un nuevo registro (empieza conducción, pausa, descanso...)
public function iniciarRegistro() {
        // Cerrar cualquier registro abierto anterior de esta jornada
        $this->cerrarRegistroAbierto($this->id_jornada);
 
        if ($this->latitud !== null && $this->longitud !== null) {
            $query = "INSERT INTO " . $this->table . "
                          (id_jornada, tipo_actividad, hora_inicio, latitud, longitud)
                      VALUES (?, ?, ?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $hora_inicio = date('Y-m-d H:i:s');
            $latitud     = (float)$this->latitud;
            $longitud    = (float)$this->longitud;
            $stmt->bind_param(
                "issdd",
                $this->id_jornada,
                $this->tipo_actividad,
                $hora_inicio,
                $latitud,
                $longitud
            );
        } else {
            $query = "INSERT INTO " . $this->table . "
                          (id_jornada, tipo_actividad, hora_inicio)
                      VALUES (?, ?, ?)";
            $stmt = $this->conn->prepare($query);
            $hora_inicio = date('Y-m-d H:i:s');
            $stmt->bind_param(
                "iss",
                $this->id_jornada,
                $this->tipo_actividad,
                $hora_inicio
            );
        }
 
        if ($stmt->execute()) {
            return $this->conn->insert_id;
        }
        return false;
    }

    // Cerrar el registro activo de una jornada
    public function cerrarRegistroAbierto($id_jornada) {
        $query = "UPDATE " . $this->table . "
                  SET hora_fin = ?
                  WHERE id_jornada = ? AND hora_fin IS NULL";
        $stmt = $this->conn->prepare($query);
        $hora_fin = date('Y-m-d H:i:s');
        $stmt->bind_param("si", $hora_fin, $id_jornada);
        return $stmt->execute();
    }

    // Obtener todos los registros de una jornada
    public function getRegistrosJornada($id_jornada) {
        $query = "SELECT id_registro, tipo_actividad, hora_inicio, hora_fin,
                         latitud, longitud,
                         TIMESTAMPDIFF(MINUTE, hora_inicio,
                             COALESCE(hora_fin, NOW())) AS duracion_minutos
                  FROM " . $this->table . "
                  WHERE id_jornada = ?
                  ORDER BY hora_inicio ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_jornada);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    //Segundos de conducción continua y total en la jornada actual
    public function getMinutosConduccion($id_jornada) {
        $query = "SELECT tipo_actividad, hora_inicio, hora_fin
                  FROM " . $this->table . "
                  WHERE id_jornada = ?
                  ORDER BY hora_inicio ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $id_jornada);
        $stmt->execute();
        $registros = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $conduccionActivaSeg = 0;
        $conduccionTotalSeg = 0;
        $pausaParte1 = false;
        $actividadActual = '';
        $tiempoActualSeg = 0;

        foreach ($registros as $registro) {
            $fin = $registro['hora_fin'] ?? date('Y-m-d H:i:s');
            $duracionSeg = strtotime($fin) - strtotime($registro['hora_inicio']);
            
            $actividadActual = $registro['tipo_actividad'];
            $tiempoActualSeg = $duracionSeg;

            if ($registro['tipo_actividad'] === 'conduccion') {
                $conduccionActivaSeg += $duracionSeg;
                $conduccionTotalSeg += $duracionSeg;
            } else if (in_array($registro['tipo_actividad'], ['pausa', 'descanso'])) {
                if (!$pausaParte1) {
                    // Margen: 44 min 30s = 2670s (en lugar de 2700s)
                    if ($duracionSeg >= 2670) {
                        // Pausa completa de 45m
                        $conduccionActivaSeg = 0;
                        $pausaParte1 = false;
                    } elseif ($duracionSeg >= 870) { 
                        // Margen: 14m 30s = 870s (en lugar de 900s)
                        // Cumple la primera parte de la fraccionada
                        $pausaParte1 = true;
                    }
                } else {
                    // Margen: 29m 30s = 1770s (en lugar de 1800s)
                    if ($duracionSeg >= 1770) {
                        // Cumple la segunda parte de 30m
                        $conduccionActivaSeg = 0;
                        $pausaParte1 = false;
                    }
                }
            }
        }
        
        return [
            'conduccion_activa' => $conduccionActivaSeg,
            'conduccion_total' => $conduccionTotalSeg,
            'pausa_parte1_cumplida' => $pausaParte1,
            'actividad_actual' => $actividadActual,
            'tiempo_actual' => $tiempoActualSeg
        ];
    }
}
?>
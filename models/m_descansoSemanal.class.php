<?php
class DescansoSemanal {
    private $conn;
    private $table = "compensaciones_descanso";

    public $id_compensacion;
    public $horas_deuda;
    public $fecha_limite;
    public $compensado;
    public $fecha_compensacion;
    public $id_usuario;
    public $id_registro;

    public function __construct($conexion) {
        $this->conn = $conexion;
    }

    // Marcar un registro de actividad como descanso semanal
    public function marcarDescansoSemanal($id_registro, $id_usuario) {
       $this->conn->begin_transaction();
        try {
        $check = $this->conn->prepare(
            "SELECT ra.id_registro, ra.hora_inicio, ra.hora_fin,
                    TIMESTAMPDIFF(MINUTE, ra.hora_inicio,
                        COALESCE(ra.hora_fin, NOW())) AS duracion
             FROM registro_actividad ra
             INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
             WHERE ra.id_registro = ? AND j.id_usuario = ?
               AND ra.tipo_actividad IN ('descanso', 'pausa')"
        );
        $check->bind_param("ii", $id_registro, $id_usuario);
        $check->execute();
        $registro = $check->get_result()->fetch_assoc();

        if (!$registro) {
            return ["error" => "Registro no encontrado"];
        }

        $duracion = (int)$registro['duracion'];

        //Clasificar el descanso semanal
        if ($duracion >= 2700) {
            // >= 45h → descanso semanal normal, sin deuda
            $tipo = 'normal';
            $deuda = 0;
        } elseif ($duracion >= 1440) {
            // >= 24h y < 45h → descanso semanal reducido, deuda = diferencia hasta 45h
            $tipo  = 'reducido';
            $deuda = 2700 - $duracion;
        } else {
            return ["error" => "El descanso debe ser de al menos 24 horas para ser semanal"];
        }

        //Marcar el registro como descanso semanal
        $update = $this->conn->prepare(
            "UPDATE registro_actividad
             SET es_descanso_semanal = 1
             WHERE id_registro = ?"
        );
        $update->bind_param("i", $id_registro);
        $update->execute();

        //Si fue reducido, crear compensación pendiente
        if ($tipo === 'reducido') {
            // Fecha límite: 3 semanas desde el fin del descanso
            $fechaFin    = $registro['hora_fin'] ?? date('Y-m-d H:i:s');
            $fechaLimite = date('Y-m-d H:i:s',
                strtotime($fechaFin . ' +21 days'));

            $insert = $this->conn->prepare(
                "INSERT INTO compensaciones_descanso
                     (id_usuario, id_registro, horas_deuda, fecha_limite)
                 VALUES (?, ?, ?, ?)"
            );
            $horasDeuda = (int)ceil($deuda / 60); // convertir a horas
            $insert->bind_param("iiis", $id_usuario, $id_registro,
                $horasDeuda, $fechaLimite);
            $insert->execute();
        }
        $this->conn->commit();

        return [
            "tipo"       => $tipo,
            "duracion_h" => intdiv($duracion, 60),
            "duracion_m" => $duracion % 60,
            "deuda_h"    => isset($deuda) ? (int)ceil($deuda / 60) : 0,
        ];
        } catch (Exception $e) {
        $this->conn->rollback();
        return ["error" => "Error interno: " . $e->getMessage()];
        }
    }

    // ---------------------------------------------------------------
    // Registrar que se ha compensado una deuda de descanso semanal
    // Se llama automáticamente cuando se detecta un descanso largo
    // tras una compensación pendiente
    // ---------------------------------------------------------------
    public function registrarCompensacion($id_usuario, $minutos_descanso) {
        $query = $this->conn->prepare(
            "SELECT id_compensacion, horas_deuda, fecha_limite
             FROM compensaciones_descanso
             WHERE id_usuario = ? AND compensado = 0
             ORDER BY fecha_limite ASC"
        );
        $query->bind_param("i", $id_usuario);
        $query->execute();
        $pendientes = $query->get_result()->fetch_all(MYSQLI_ASSOC);

        if (empty($pendientes)) return [];

        $compensadas = [];
        $minutosDisponibles = $minutos_descanso;

        foreach ($pendientes as $p) {
            $minutosNecesarios = $p['horas_deuda'] * 60;
            if ($minutosDisponibles >= $minutosNecesarios) {
                // Marcar como compensada
                $update = $this->conn->prepare(
                    "UPDATE compensaciones_descanso
                     SET compensado = 1, fecha_compensacion = NOW()
                     WHERE id_compensacion = ?"
                );
                $update->bind_param("i", $p['id_compensacion']);
                $update->execute();

                $compensadas[]      = $p['id_compensacion'];
                $minutosDisponibles -= $minutosNecesarios;
            }
        }
        return $compensadas;
    }

    // ---------------------------------------------------------------
    // Obtener compensaciones pendientes de un usuario
    // ---------------------------------------------------------------
    public function getCompensacionesPendientes($id_usuario) {
        $query = $this->conn->prepare(
            "SELECT id_compensacion, horas_deuda, fecha_limite,
                    DATEDIFF(fecha_limite, NOW()) AS dias_restantes
             FROM compensaciones_descanso
             WHERE id_usuario = ? AND compensado = 0
             ORDER BY fecha_limite ASC"
        );
        $query->bind_param("i", $id_usuario);
        $query->execute();
        return $query->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ---------------------------------------------------------------
    // Comprobar días consecutivos trabajados sin descanso semanal
    // Alerta si llevan >= 6 días sin uno
    // ---------------------------------------------------------------
    public function getDiasConsecutivosSinDescansoSemanal($id_usuario) {
        // Busca el último descanso semanal completado
        $query = $this->conn->prepare(
            "SELECT ra.hora_fin
             FROM registro_actividad ra
             INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
             WHERE j.id_usuario = ?
               AND ra.es_descanso_semanal = 1
               AND ra.hora_fin IS NOT NULL
             ORDER BY ra.hora_fin DESC
             LIMIT 1"
        );
        $query->bind_param("i", $id_usuario);
        $query->execute();
        $fila = $query->get_result()->fetch_assoc();

        $desde = $fila
            ? $fila['hora_fin']
            : date('Y-m-d H:i:s', strtotime('-30 days'));

        // Contar jornadas desde ese descanso
        $queryJornadas = $this->conn->prepare(
            "SELECT COUNT(DISTINCT DATE(fecha_inicio)) AS dias
             FROM jornadas
             WHERE id_usuario = ?
               AND fecha_inicio > ?
               AND estado = 'cerrada'"
        );
        $queryJornadas->bind_param("is", $id_usuario, $desde);
        $queryJornadas->execute();
        $fila2 = $queryJornadas->get_result()->fetch_assoc();

        return (int)($fila2['dias'] ?? 0);
    }

    // ---------------------------------------------------------------
    // Comprobar todas las alertas relacionadas con descanso semanal
    // ---------------------------------------------------------------
    public function comprobarAlertasSemanales($id_jornada, $id_usuario) {
        $alertas = [];

        // --- Días consecutivos sin descanso semanal ---
        $dias = $this->getDiasConsecutivosSinDescansoSemanal($id_usuario);

        if ($dias >= 6) {
            $alertas[] = [
                'tipo'    => 'descanso_semanal_pendiente',
                'mensaje' => "Llevas $dias días trabajando sin descanso semanal. "
                           . "Debes tomar un descanso de al menos 24h lo antes posible.",
            ];
        } elseif ($dias == 5) {
            $alertas[] = [
                'tipo'    => 'descanso_semanal_pendiente',
                'mensaje' => "Llevas 5 días trabajando. Mañana debes comenzar tu descanso semanal.",
            ];
        }

        // --- Compensaciones vencidas ---
        $pendientes = $this->getCompensacionesPendientes($id_usuario);
        foreach ($pendientes as $p) {
            if ($p['dias_restantes'] < 0) {
                $alertas[] = [
                    'tipo'    => 'compensacion_vencida',
                    'mensaje' => "Tienes una compensación de {$p['horas_deuda']}h vencida. "
                               . "Contacta con tu empresa.",
                ];
            } elseif ($p['dias_restantes'] <= 3) {
                $alertas[] = [
                    'tipo'    => 'compensacion_pendiente',
                    'mensaje' => "Debes compensar {$p['horas_deuda']}h de descanso semanal "
                               . "en los próximos {$p['dias_restantes']} días.",
                ];
            }
        }

        $alertasGeneradas = [];
        foreach ($alertas as $a) {
            $insert = $this->conn->prepare(
                "INSERT INTO alertas (id_jornada, tipo_alerta, mensaje)
                 VALUES (?, ?, ?)"
            );
            $insert->bind_param("iss", $id_jornada, $a['tipo'], $a['mensaje']);
            if ($insert->execute()) {
                $alertasGeneradas[] = $a['tipo'];
            }
        }
        return $alertasGeneradas;
    }

    // Obtener el último descanso semanal completado (para historial)
    public function getUltimoDescansoSemanal($id_usuario) {
        $query = $this->conn->prepare(
            "SELECT ra.hora_inicio, ra.hora_fin,
                    TIMESTAMPDIFF(MINUTE, ra.hora_inicio,
                        COALESCE(ra.hora_fin, NOW())) AS duracion_minutos
             FROM registro_actividad ra
             INNER JOIN jornadas j ON ra.id_jornada = j.id_jornada
             WHERE j.id_usuario = ?
               AND ra.es_descanso_semanal = 1
               AND ra.hora_fin IS NOT NULL
             ORDER BY ra.hora_fin DESC
             LIMIT 1"
        );
        $query->bind_param("i", $id_usuario);
        $query->execute();
        return $query->get_result()->fetch_assoc();
    }
}
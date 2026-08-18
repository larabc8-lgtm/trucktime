# TruckTime - Backend API REST

Backend del proyecto **TruckTime**, una aplicación de gestión de tiempos de conducción y descanso para camioneros profesionales. Desarrollado como Proyecto Final del Grado Superior en Desarrollo de Aplicaciones Multiplataforma (DAM).

Cumple con el **Reglamento CE 561/2006** sobre tiempos de conducción y descanso.

## Tecnologías

- **PHP 8.x**
- **Slim Framework 4** - Framework ligero para APIs REST
- **MySQL** - Base de datos relacional
- **Composer** - Gestor de dependencias

## Estructura del proyecto

```
trucktime/
├── index.php                 # Entry point, configuración de Slim y rutas
├── includes.php              # Funciones auxiliares (jsonResponse)
├── composer.json             # Dependencias (Slim 4)
├── config/
│   └── conexion.php          # Conexión a MySQL
├── endpoints/                # Rutas de la API
│   ├── usuarios.endpoints.php
│   ├── jornadas.endpoints.php
│   ├── registros.endpoints.php
│   ├── alertas.endpoints.php
│   └── descansoSemanal.endpoints.php
├── models/                   # Modelos de datos (capa de acceso a BD)
│   ├── m_usuario.class.php
│   ├── m_jornada.class.php
│   ├── m_registroactividad.class.php
│   ├── m_alerta.class.php
│   └── m_descansoSemanal.class.php
└── db_schema.txt             # Esquema de la base de datos
```

## API REST

Base URL: `http://localhost/trucktime`

### Usuarios

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/usuarios/login` | Inicio de sesión |
| POST | `/usuarios/registro` | Registro de nuevo usuario |

### Jornadas

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/jornadas/abrir` | Abrir una nueva jornada |
| PUT | `/jornadas/cerrar/{id}` | Cerrar jornada |
| GET | `/jornadas/activa/{id_usuario}` | Obtener jornada activa |
| GET | `/jornadas/usuario/{id}` | Historial de jornadas |
| GET | `/jornadas/resumen/{id_usuario}` | Resumen semanal/mensual |

### Registros de actividad

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/registros/iniciar` | Registrar inicio de actividad |
| GET | `/registros/jornada/{id}` | Registros de una jornada |
| GET | `/registros/conduccion/{id_jornada}` | Minutos de conducción |

### Alertas

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/alertas/comprobar` | Comprobar y generar alertas |
| POST | `/alertas/crear` | Crear alerta manualmente |
| GET | `/alertas/jornada/{id}` | Alertas de una jornada |
| GET | `/alertas/noleidas/{id_usuario}` | Alertas no leídas |
| PUT | `/alertas/leer/{id}` | Marcar alerta como leída |
| PUT | `/alertas/leertodas/{id_jornada}` | Marcar todas como leídas |

### Descanso semanal

| Método | Ruta | Descripción |
|--------|------|-------------|
| POST | `/descanso-semanal/marcar` | Marcar registro como descanso semanal |
| GET | `/descanso-semanal/estado/{id_usuario}` | Estado actual (días, compensaciones) |
| POST | `/descanso-semanal/comprobar` | Comprobar alertas al iniciar jornada |
| GET | `/descanso-semanal/ultimo/{id_usuario}` | Último descanso completado |

## Base de datos

### Diagrama de tablas

```
usuarios
├── id_usuario (PK)
├── nombre, apellidos
├── email (UNIQUE)
├── password
├── activo
└── fecha_registro

jornadas
├── id_jornada (PK)
├── id_usuario (FK → usuarios)
├── fecha_inicio, fecha_fin
├── duracion_conduccion_total
├── duracion_descanso_total
├── estado (activa | cerrada | incompleta)
├── tipo_descanso (normal | fraccionado | reducido | insuficiente)
└── descansos_reducidos_semana

registro_actividad
├── id_registro (PK)
├── id_jornada (FK → jornadas)
├── tipo_actividad (conduccion | descanso | pausa | disponibilidad | otros_trabajos)
├── hora_inicio, hora_fin
├── latitud, longitud
└── es_descanso_semanal

alertas
├── id_alerta (PK)
├── id_jornada (FK → jornadas)
├── tipo_alerta (conduccion_maxima_diaria | pausa_obligatoria | descanso_insuficiente | ...)
├── mensaje
├── fecha_hora
└── leida

compensaciones_descanso
├── id_compensacion (PK)
├── id_usuario (FK → usuarios)
├── id_registro (FK → registro_actividad)
├── horas_deuda
├── fecha_limite
├── compensado
└── fecha_compensacion
```

## Instalación

```bash
# Clonar el repositorio
git clone https://github.com/larabc8-lgtm/trucktime.git

# Instalar dependencias
composer install

# Configurar la base de datos en config/conexion.php

# Importar el esquema desde db_schema.txt en MySQL

# Configurar Apache/XAMPP para servir desde htdocs/trucktime
```

## Reglamento CE 561/2006

La API implementa las siguientes reglas de forma automática:

| Regla | Implementación |
|-------|----------------|
| 4h30 conducción máxima | Alertas a las 4h, 4h15 y 4h30 de conducción continua |
| Pausa obligatoria 45 min | Cálculo automático al registrar pausa |
| Pausa fraccionada 15+30 min | Validación de dos bloques de descanso |
| 9h descanso diario (reducido) | Máximo 3 veces por semana |
| 11h descanso diario (estándar) | Descanso por defecto |
| 56h descanso semanal | Seguimiento de días consecutivos sin descanso |
| Compensación por descanso reducido | Registro automático de deuda y fecha límite |

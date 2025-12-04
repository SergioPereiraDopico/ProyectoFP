<?php

// ---------- CONFIGURACIÓN ----------

/**
 * Configuración inicial
 * host: donde está el servidor, en este caso mi propio dispositivo
 * port: como el 3306 está ocupado por MySQL uso el 3307 para evitar problemas
 * db: la base de datos que he creado con XAMPP
 * user: root por defecto en XAMPP
 * passw: por defecto sin ella
 * xmlFile: nuestro archivo de datos
 */
$host = 'localhost';
$port = '3307';
$db   = 'bd_autopsias';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';
$xmlFile = __DIR__ . '/dataset.xml'; // ruta del XML



// ---------- FUNCIONES ----------

/**
 * Normaliza el valor leído del XML:
 * cadenas vacías → NULL
 * Creamos esta función para convertir las cadenas vacías en nulos
 * y para convertir los formatos de fecha 
 */
FUNCTION normalize_value($v) {
    IF ($v === NULL) RETURN NULL;
    $v = trim($v);
    IF ($v === '') RETURN NULL;

    // Detectar fechas tipo 4/5/1973 o 01/08/2023
    IF (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $v)) {
        $dt = DateTime::createFromFormat('d/m/Y', $v);
        IF ($dt) {
            RETURN $dt->format('Y-m-d');
        }
    }

    RETURN $v;
}


/**
 * Genera una consulta INSERT ... ON DUPLICATE KEY UPDATE para la tabla indicada.
 * - INSERTa una nueva fila con las columnas proporcionadas.
 * - Si la clave primaria ya existe, actualiza solo las columnas que no sean ID_*.
 * De esta forma se obtiene un "UPSERT" genérico que sirve para cualquier tabla
 * y evita modIFicar valores de claves primarias.
 */
FUNCTION build_upsert_sql($table, $cols) {
    $colsEsc = array_map(fn($c) => "`$c`", $cols);
    $placeholders = array_map(fn($c) => ":" . $c, $cols);

    $INSERT = "INSERT INTO `$table` (" . implode(", ", $colsEsc) . ") VALUES (" . implode(", ", $placeholders) . ")";

    // Evita actualizar claves primarias (asumidas como ID_*)
    $updates = [];
    foreach ($cols as $c) {
        IF (stripos($c, 'ID_') === 0) continue;
        $updates[] = "`$c` = VALUES(`$c`)";
    }

    IF (COUNT($updates) > 0) {
        $INSERT .= " ON DUPLICATE KEY UPDATE " . implode(", ", $updates);
    }

    RETURN $INSERT;
}


/**
 * ---------- CONEXIÓN ----------
 * Establece la conexión con la base de datos utilizando PDO.
 * Se construye el DSN especIFicando host, puerto, nombre de la BD y el charset.
 * Se configuran opciones importantes:
 *      • ERRMODE_EXCEPTION: lanza excepciones cuando ocurre un error SQL.
 *      • EMULATE_PREPARES = false: usa consultas preparadas reales del motor.
 *      • DEFAULT_FETCH_MODE = FETCH_ASSOC: devuelve resultados como arrays asociativos.
 * Si la conexión falla, se captura la excepción y se muestra un mensaje claro
 * antes de finalizar la ejecución del script.
 */
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    echo "Error al conectar a la base de datos: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

/**
 * ---------- CARGAR XML ----------
 * Comprueba que el archivo XML existe y lo carga.
 *
 * Si el archivo no está, el script se detiene.
 * Si el XML tiene errores o no se puede leer, se muestran los errores
 * y el programa también se detiene.
 *
 * Esto asegura que solo trabajamos con un XML válido antes de importar datos.
 */
IF (!file_exists($xmlFile)) {
    echo "No se encuentra el archivo XML: $xmlFile" . PHP_EOL;
    exit(1);
}

libxml_use_internal_errors(true);
$xml = simplexml_load_file($xmlFile);
IF ($xml === false) {
    echo "Error al parsear XML:" . PHP_EOL;
    foreach (libxml_get_errors() as $err) {
        echo $err->message;
    }
    exit(1);
}

/** 
 * ---------- IMPORTACIÓN ----------
 * Recorre todo el XML e inserta cada elemento en su tabla correspondiente.
 * Si todo funciona, se confirma la transacción (commit).
 * Si ocurre cualquier error durante la importación, se vacian todos 
 * los cambios y se muestra el mensaje de error.
 */
$pdo->beginTransaction();
try {
    foreach ($xml->children() as $element) {
        $table = $element->getName();
        $cols = [];
        $params = [];

        foreach ($element->children() as $field) {
            $col = $field->getName();
            $val = normalize_value((string)$field);
            $cols[] = $col;
            $params[$col] = $val;
        }

        IF (empty($cols)) continue;

        $sql = build_upsert_sql($table, $cols);
        $stmt = $pdo->prepare($sql);

        foreach ($params as $col => $val) {
            IF ($val === NULL) {
                $stmt->bindValue(':' . $col, NULL, PDO::PARAM_NULL);
            } ELSE {
                $stmt->bindValue(':' . $col, $val, PDO::PARAM_STR);
            }
        }

        $stmt->execute();
    }

    $pdo->commit();
    echo "Importación completada correctamente." . PHP_EOL;
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "Error durante la importación: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
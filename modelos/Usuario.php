<?php
/**
 * Modelo de Usuario — SIP-Postgrado UNEFA
 * Encapsula la persistencia, búsquedas y operaciones sobre la tabla 'usuarios'.
 */

class Usuario
{
    /**
     * Busca un usuario por su identificador único.
     */
    public static function buscarPorId(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT u.*, r.nombre AS rol_nombre, s.nombre AS sede_nombre
                               FROM usuarios u
                               LEFT JOIN roles r ON r.id = u.rol_id
                               LEFT JOIN sedes s ON s.id = u.sede_id
                               WHERE u.id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Busca un usuario por tipo y número de documento.
     */
    public static function buscarPorDocumento(PDO $pdo, string $tipoDoc, string $numDoc): ?array
    {
        $stmt = $pdo->prepare('SELECT u.*, r.nombre AS rol_nombre
                               FROM usuarios u
                               LEFT JOIN roles r ON r.id = u.rol_id
                               WHERE u.tipo_cedula = :tipo AND u.numero_documento = :ndoc
                               LIMIT 1');
        $stmt->execute([
            ':tipo' => strtoupper(trim($tipoDoc)),
            ':ndoc' => trim($numDoc),
        ]);
        $res = $stmt->fetch();
        return $res ?: null;
    }

    /**
     * Cuenta el total de usuarios, opcionalmente filtrado por rol.
     */
    public static function contar(PDO $pdo, ?int $rolId = null): int
    {
        if ($rolId !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM usuarios WHERE rol_id = :rol');
            $stmt->execute([':rol' => $rolId]);
        } else {
            $stmt = $pdo->query('SELECT COUNT(*) FROM usuarios');
        }
        return (int) $stmt->fetchColumn();
    }

    /**
     * Obtiene una lista paginada de usuarios con filtro opcional de búsqueda.
     */
    public static function listarPaginado(
        PDO $pdo,
        int $pagina = 1,
        int $porPagina = 15,
        ?string $busqueda = null,
        ?int $rolId = null
    ): array {
        $pagina = max(1, $pagina);
        $porPagina = max(1, $porPagina);
        $offset = ($pagina - 1) * $porPagina;

        $where = [];
        $params = [];

        if ($rolId !== null) {
            $where[] = 'u.rol_id = :rol';
            $params[':rol'] = $rolId;
        }

        if ($busqueda !== null && trim($busqueda) !== '') {
            $where[] = '(u.nombres ILIKE :q OR u.apellidos ILIKE :q OR u.numero_documento ILIKE :q OR u.email ILIKE :q)';
            $params[':q'] = '%' . trim($busqueda) . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT u.id, u.tipo_cedula, u.numero_documento, u.nombres, u.apellidos, u.email,
                       u.rol_id, r.nombre AS rol_nombre, s.nombre AS sede_nombre, u.estatus, u.estado_aspirante
                FROM usuarios u
                LEFT JOIN roles r ON r.id = u.rol_id
                LEFT JOIN sedes s ON s.id = u.sede_id
                $whereSql
                ORDER BY u.id DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $porPagina, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Cuenta el total de usuarios coincidentes con los filtros aplicados.
     */
    public static function contarFiltrados(PDO $pdo, ?string $busqueda = null, ?int $rolId = null): int
    {
        $where = [];
        $params = [];

        if ($rolId !== null) {
            $where[] = 'rol_id = :rol';
            $params[':rol'] = $rolId;
        }

        if ($busqueda !== null && trim($busqueda) !== '') {
            $where[] = '(nombres ILIKE :q OR apellidos ILIKE :q OR numero_documento ILIKE :q OR email ILIKE :q)';
            $params[':q'] = '%' . trim($busqueda) . '%';
        }

        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT COUNT(*) FROM usuarios $whereSql";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Actualiza el estatus de un usuario (Activo / Bloqueado / Inactivo).
     */
    public static function cambiarEstatus(PDO $pdo, int $id, string $nuevoEstatus): bool
    {
        $stmt = $pdo->prepare('UPDATE usuarios SET estatus = :estatus WHERE id = :id');
        return $stmt->execute([
            ':estatus' => $nuevoEstatus,
            ':id'      => $id,
        ]);
    }

    // ============================================================
    // CAPA 3 — Prevención de duplicados
    // ============================================================

    /**
     * Verifica si ya existe un usuario con el mismo email o cédula (tipo+número).
     *
     * @param  PDO         $pdo
     * @param  string      $email   Correo electrónico a comprobar.
     * @param  string      $tipoCi  Tipo de documento (V, E, J, etc.).
     * @param  string      $numDoc  Número de documento a comprobar.
     * @param  int|null    $excluirId  ID de usuario a excluir (para actualizaciones).
     * @return array{email: bool, cedula: bool}
     *         'email'  => true si el correo ya está en uso.
     *         'cedula' => true si la cédula ya está en uso.
     */
    public static function existeDuplicado(
        PDO $pdo,
        string $email,
        string $tipoCi,
        string $numDoc,
        ?int $excluirId = null
    ): array {
        $resultado = ['email' => false, 'cedula' => false];

        $excluirSql = $excluirId !== null ? 'AND id <> :excluir_id' : '';
        $params     = [
            ':email' => strtolower(trim($email)),
            ':tipo'  => strtoupper(trim($tipoCi)),
            ':ndoc'  => trim($numDoc),
        ];
        if ($excluirId !== null) {
            $params[':excluir_id'] = $excluirId;
        }

        // Verificar email
        $sqlEmail = "SELECT 1 FROM usuarios WHERE email = :email $excluirSql LIMIT 1";
        $stmtEmail = $pdo->prepare($sqlEmail);
        $stmtEmail->execute(array_filter($params, fn($k) => in_array($k, [':email', ':excluir_id'], true), ARRAY_FILTER_USE_KEY));
        $resultado['email'] = (bool) $stmtEmail->fetchColumn();

        // Verificar cédula (tipo + número de documento)
        $sqlCi = "SELECT 1 FROM usuarios WHERE tipo_cedula = :tipo AND numero_documento = :ndoc $excluirSql LIMIT 1";
        $stmtCi = $pdo->prepare($sqlCi);
        $stmtCi->execute(array_filter($params, fn($k) => in_array($k, [':tipo', ':ndoc', ':excluir_id'], true), ARRAY_FILTER_USE_KEY));
        $resultado['cedula'] = (bool) $stmtCi->fetchColumn();

        return $resultado;
    }


    /**
     * Registra un nuevo usuario y retorna su ID generado.
     *
     * @throws RuntimeException Con mensaje claro si hay duplicado de cédula o correo,
     *                          o si ocurre otra violación de restricción única.
     */
    public static function crear(PDO $pdo, array $datos): int
    {
        $tipoDoc = strtoupper(trim($datos['tipo_documento'] ?? 'V'));
        $numDoc  = trim($datos['numero_documento'] ?? '');
        $ciLimpia = preg_replace('/\D/', '', $numDoc) ?: '0';

        // ---- Prevención de duplicados (verificación previa al INSERT) ----
        // Detectamos conflictos antes de llegar a la BD para dar mensajes
        // más claros que los que retorna PostgreSQL.
        $duplicados = self::existeDuplicado($pdo, $datos['email'] ?? '', $tipoDoc, $numDoc);
        if ($duplicados['cedula']) {
            throw new \RuntimeException(
                'Ya existe un usuario registrado con la cédula ' . $tipoDoc . '-' . $numDoc . '.'
            );
        }
        if ($duplicados['email']) {
            throw new \RuntimeException(
                'El correo electrónico «' . ($datos['email'] ?? '') . '» ya está registrado.'
            );
        }

        $sql = "INSERT INTO usuarios (tipo_cedula, numero_documento, cedula, nombres, apellidos, email, password, rol_id, sede_id, estatus, estado_aspirante)
                VALUES (:tipo, :ndoc, :ci, :nom, :ape, :mail, :pass, :rol, :sede, :estatus, :estado_asp)
                RETURNING id";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':tipo'       => $tipoDoc,
                ':ndoc'       => $numDoc,
                ':ci'         => $ciLimpia,
                ':nom'        => trim($datos['nombres'] ?? 'Pendiente'),
                ':ape'        => trim($datos['apellidos'] ?? 'Pendiente'),
                ':mail'       => strtolower(trim($datos['email'] ?? '')),
                ':pass'       => password_hash($datos['password'], PASSWORD_DEFAULT),
                ':rol'        => (int) ($datos['rol_id'] ?? 5),
                ':sede'       => !empty($datos['sede_id']) ? (int) $datos['sede_id'] : null,
                ':estatus'    => $datos['estatus'] ?? 'Activo',
                ':estado_asp' => $datos['estado_aspirante'] ?? 'Admitido',
            ]);

            return (int) $stmt->fetchColumn();

        } catch (\PDOException $e) {
            // SQLSTATE 23505 = unique_violation en PostgreSQL
            // Capturamos como segunda línea de defensa (race condition o
            // registros creados desde otro hilo entre la verificación y el INSERT).
            if ($e->getCode() === '23505') {
                $msg = $e->getMessage();
                // Intentamos inferir qué campo causó la violación
                if (stripos($msg, 'email') !== false) {
                    throw new \RuntimeException('El correo electrónico ya está registrado en el sistema.');
                }
                if (stripos($msg, 'cedula') !== false || stripos($msg, 'numero_documento') !== false) {
                    throw new \RuntimeException('Ya existe un usuario con esa cédula/documento de identidad.');
                }
                throw new \RuntimeException('Datos duplicados: ya existe un registro con esa información.');
            }
            // Otro tipo de error de BD: relanzar para que el controlador lo maneje
            throw $e;
        }
    }
}


<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('Europe/Lisbon');

if (!(bool) ($_SESSION['authenticated'] ?? false)) {
    header('Location: index.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$clientsFile = $dataDir . '/clients.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

if (!file_exists($clientsFile)) {
    file_put_contents($clientsFile, json_encode([], JSON_PRETTY_PRINT));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function loadClients(string $clientsFile): array
{
    $raw = file_get_contents($clientsFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveClients(string $clientsFile, array $clients): bool
{
    $json = json_encode($clients, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($clientsFile, $json, LOCK_EX) !== false;
}

function normalizeNif(string $nif): string
{
    return preg_replace('/\D+/', '', $nif) ?? '';
}

function clientStatus(array $client, ?DateTimeImmutable $now = null): string
{
    if (($client['status'] ?? '') === 'cancelado') {
        return 'cancelado';
    }

    $now ??= new DateTimeImmutable('now');
    $lastUpdatedRaw = (string) ($client['last_updated_at'] ?? $client['created_at'] ?? '');
    $lastUpdated = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $lastUpdatedRaw);

    if ($lastUpdated === false || $lastUpdated->modify('+12 months') <= $now) {
        return 'pendente_atualizacao';
    }

    return 'ativo';
}

function validateClientData(array $form, array $clients, ?string $currentId = null): array
{
    $errors = [];

    if ($form['first_name'] === '') {
        $errors[] = 'Nome e obrigatorio.';
    }

    if ($form['last_name'] === '') {
        $errors[] = 'Apelido e obrigatorio.';
    }

    if ($form['nif'] === '') {
        $errors[] = 'NIF e obrigatorio.';
    }

    if ($form['email'] === '') {
        $errors[] = 'E-mail e obrigatorio.';
    }

    if ($form['phone'] === '') {
        $errors[] = 'Telefone (telemovel) e obrigatorio.';
    }

    $normalizedNif = normalizeNif($form['nif']);
    if ($form['nif'] !== '' && !preg_match('/^\d{9}$/', $normalizedNif)) {
        $errors[] = 'NIF invalido. Use 9 digitos.';
    }

    if ($form['email'] !== '' && !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'E-mail invalido.';
    }

    if ($form['phone'] !== '' && !preg_match('/^[0-9+()\-\s]{7,20}$/', $form['phone'])) {
        $errors[] = 'Telefone invalido.';
    }

    foreach ($clients as $client) {
        if (($client['id'] ?? '') === $currentId) {
            continue;
        }

        $existingNif = normalizeNif((string) ($client['nif'] ?? ''));
        if ($existingNif !== '' && $existingNif === $normalizedNif) {
            $errors[] = 'Ja existe um cliente com este NIF.';
            break;
        }
    }

    return $errors;
}

$errors = [];
$success = null;
$clients = loadClients($clientsFile);
$now = new DateTimeImmutable('now');

$form = [
    'first_name' => '',
    'last_name' => '',
    'nif' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'postal_code' => '',
    'municipio' => '',
    'conselho' => '',
];

if (($_POST['action'] ?? '') === 'create_client') {
    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $normalizedNif = normalizeNif($form['nif']);
    $errors = validateClientData($form, $clients);

    if (!$errors) {
        $clients[] = [
            'id' => bin2hex(random_bytes(8)),
            'first_name' => $form['first_name'],
            'last_name' => $form['last_name'],
            'nif' => $normalizedNif,
            'email' => strtolower($form['email']),
            'phone' => $form['phone'],
            'address' => $form['address'],
            'postal_code' => $form['postal_code'],
            'municipio' => $form['municipio'],
            'conselho' => $form['conselho'],
            'status' => 'ativo',
            'created_at' => $now->format(DateTimeInterface::ATOM),
            'last_updated_at' => $now->format(DateTimeInterface::ATOM),
        ];

        usort($clients, static function (array $a, array $b): int {
            $nameA = strtolower(trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? '')));
            $nameB = strtolower(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')));
            return $nameA <=> $nameB;
        });

        if (saveClients($clientsFile, $clients)) {
            $success = 'Cliente cadastrado com sucesso.';
            foreach ($form as $key => $_) {
                $form[$key] = '';
            }
        } else {
            $errors[] = 'Falha ao salvar cadastro de cliente.';
        }
    }
}

$editId = trim((string) ($_GET['edit'] ?? ''));
if ($editId !== '') {
    foreach ($clients as $client) {
        if (($client['id'] ?? '') !== $editId) {
            continue;
        }

        foreach ($form as $key => $_) {
            $form[$key] = (string) ($client[$key] ?? '');
        }
        break;
    }
}

if (($_POST['action'] ?? '') === 'update_client') {
    $editId = trim((string) ($_POST['client_id'] ?? ''));
    foreach ($form as $key => $_) {
        $form[$key] = trim((string) ($_POST[$key] ?? ''));
    }

    $clientIndex = null;
    foreach ($clients as $index => $client) {
        if (($client['id'] ?? '') === $editId) {
            $clientIndex = $index;
            break;
        }
    }

    if ($clientIndex === null) {
        $errors[] = 'Cliente nao encontrado.';
    } else {
        $errors = validateClientData($form, $clients, $editId);
    }

    if (!$errors && $clientIndex !== null) {
        $clients[$clientIndex] = array_merge($clients[$clientIndex], $form, [
            'nif' => normalizeNif($form['nif']),
            'email' => strtolower($form['email']),
            'status' => 'ativo',
            'last_updated_at' => $now->format(DateTimeInterface::ATOM),
        ]);

        if (saveClients($clientsFile, $clients)) {
            $success = 'Dados do cliente atualizados. Status: ativo.';
            $editId = '';
        } else {
            $errors[] = 'Falha ao salvar atualizacao do cliente.';
        }
    }
}

if (($_POST['action'] ?? '') === 'mark_updated' || ($_POST['action'] ?? '') === 'change_client_status') {
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    foreach ($clients as $index => $client) {
        if (($client['id'] ?? '') !== $clientId) {
            continue;
        }

        if (($_POST['action'] ?? '') === 'mark_updated') {
            $clients[$index]['status'] = 'ativo';
            $clients[$index]['last_updated_at'] = $now->format(DateTimeInterface::ATOM);
            $success = 'Cliente marcado como atualizado. Status: ativo.';
        } else {
            $newStatus = ($_POST['status'] ?? '') === 'cancelado' ? 'cancelado' : 'ativo';
            $clients[$index]['status'] = $newStatus;
            $success = $newStatus === 'cancelado' ? 'Cliente cancelado.' : 'Cliente reativado.';
        }

        if (!saveClients($clientsFile, $clients)) {
            $errors[] = 'Falha ao atualizar status do cliente.';
            $success = null;
        }
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro de Clientes - Gestao Cowork</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;700&family=Playfair+Display:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #10181c;
            --panel: #17242b;
            --card: #1c2c34;
            --line: #2b414e;
            --gold: #c9a96e;
            --text: #eaf0f2;
            --muted: #95a8b1;
            --ok: #5db38b;
            --err: #d56f6f;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(circle at 90% 10%, #1e2f38 0%, var(--bg) 50%);
            color: var(--text);
        }

        .wrap {
            width: min(1180px, 94vw);
            margin: 28px auto;
        }

        .panel {
            border: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent), var(--panel);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        h1,
        h2 {
            margin: 0 0 10px;
            font-family: 'Playfair Display', serif;
            color: var(--gold);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .btn-ghost {
            text-decoration: none;
            color: var(--text);
            border: 1px solid var(--line);
            padding: 10px 14px;
            border-radius: 10px;
            display: inline-block;
            font-size: 14px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-top: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field.full {
            grid-column: 1 / -1;
        }

        label {
            font-size: 13px;
            color: var(--muted);
        }

        input,
        button {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px;
            font: inherit;
        }

        input {
            background: #122028;
            color: var(--text);
        }

        button {
            background: linear-gradient(135deg, var(--gold), #b48d49);
            color: #0f1a1f;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-danger {
            background: transparent;
            border-color: var(--err);
            color: var(--text);
        }

        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-ativo {
            color: #78c99e;
            background: rgba(93, 179, 139, 0.15);
        }

        .status-cancelado {
            color: #ef9797;
            background: rgba(213, 111, 111, 0.15);
        }

        .status-pendente_atualizacao {
            color: #e8c981;
            background: rgba(201, 169, 110, 0.15);
        }

        .row-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .row-actions form {
            display: inline;
        }

        .row-actions button,
        .row-actions a {
            padding: 7px 9px;
            font-size: 12px;
            white-space: nowrap;
        }

        .notice {
            border-radius: 10px;
            padding: 12px;
            margin-bottom: 14px;
            font-size: 14px;
        }

        .notice.ok {
            background: rgba(93, 179, 139, 0.14);
            border: 1px solid rgba(93, 179, 139, 0.35);
        }

        .notice.err {
            background: rgba(213, 111, 111, 0.14);
            border: 1px solid rgba(213, 111, 111, 0.35);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 18px;
        }

        th,
        td {
            text-align: left;
            border-bottom: 1px solid #304854;
            padding: 8px 6px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-weight: 500;
        }

        .empty {
            margin-top: 14px;
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 980px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <section class="panel">
            <div class="topbar">
                <div>
                    <h1>Cadastro de Clientes</h1>
                    <p>Registe clientes para depois usar na pagina de locacoes</p>
                </div>
                <a class="btn-ghost" href="index.php">Voltar para Locacoes</a>
            </div>

            <?php if ($success !== null): ?>
                <div class="notice ok"><?= h($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="notice err"><?= h($error) ?></div>
            <?php endforeach; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="action" value="<?= $editId !== '' ? 'update_client' : 'create_client' ?>">
                <?php if ($editId !== ''): ?>
                    <input type="hidden" name="client_id" value="<?= h($editId) ?>">
                    <p class="notice ok">A editar cliente. Ao guardar, os dados ficam atualizados e o status passa para ativo.</p>
                <?php endif; ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="first_name">Nome *</label>
                        <input id="first_name" name="first_name" required value="<?= h($form['first_name']) ?>">
                    </div>
                    <div class="field">
                        <label for="last_name">Apelido *</label>
                        <input id="last_name" name="last_name" required value="<?= h($form['last_name']) ?>">
                    </div>

                    <div class="field">
                        <label for="nif">NIF * (unico)</label>
                        <input id="nif" name="nif" required maxlength="20" value="<?= h($form['nif']) ?>">
                    </div>
                    <div class="field">
                        <label for="email">E-mail *</label>
                        <input id="email" name="email" type="email" required value="<?= h($form['email']) ?>">
                    </div>

                    <div class="field">
                        <label for="phone">Telefone (telemovel) *</label>
                        <input id="phone" name="phone" required value="<?= h($form['phone']) ?>">
                    </div>
                    <div class="field">
                        <label for="postal_code">Codigo postal</label>
                        <input id="postal_code" name="postal_code" value="<?= h($form['postal_code']) ?>">
                    </div>

                    <div class="field full">
                        <label for="address">Morada</label>
                        <input id="address" name="address" value="<?= h($form['address']) ?>">
                    </div>

                    <div class="field">
                        <label for="municipio">Municipio</label>
                        <input id="municipio" name="municipio" value="<?= h($form['municipio']) ?>">
                    </div>
                    <div class="field">
                        <label for="conselho">Conselho</label>
                        <input id="conselho" name="conselho" value="<?= h($form['conselho']) ?>">
                    </div>

                    <div class="field full">
                        <button type="submit"><?= $editId !== '' ? 'Atualizar cliente' : 'Guardar cliente' ?></button>
                    </div>
                </div>
            </form>

            <h2 style="margin-top:24px;">Clientes registados</h2>
            <?php if (!$clients): ?>
                <div class="empty">Ainda nao existem clientes registados.</div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Nome completo</th>
                            <th>NIF</th>
                            <th>E-mail</th>
                            <th>Telefone</th>
                            <th>Morada</th>
                            <th>Codigo postal</th>
                            <th>Municipio</th>
                            <th>Conselho</th>
                            <th>Status</th>
                            <th>Acoes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($clients as $client): ?>
                            <?php $fullName = trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? '')); ?>
                            <tr>
                                <td><?= h($fullName) ?></td>
                                <td><?= h((string) ($client['nif'] ?? '')) ?></td>
                                <td><?= h((string) ($client['email'] ?? '')) ?></td>
                                <td><?= h((string) ($client['phone'] ?? '')) ?></td>
                                <td><?= h((string) ($client['address'] ?? '')) ?></td>
                                <td><?= h((string) ($client['postal_code'] ?? '')) ?></td>
                                <td><?= h((string) ($client['municipio'] ?? '')) ?></td>
                                <td><?= h((string) ($client['conselho'] ?? '')) ?></td>
                                <?php $status = clientStatus($client, $now); ?>
                                <td><span class="status status-<?= h($status) ?>"><?= h(str_replace('_', ' ', $status)) ?></span></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn-ghost" href="?edit=<?= h((string) $client['id']) ?>">Editar</a>
                                        <?php if ($status === 'pendente_atualizacao'): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="mark_updated">
                                                <input type="hidden" name="client_id" value="<?= h((string) $client['id']) ?>">
                                                <button type="submit">Atualizado</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($status === 'cancelado'): ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="change_client_status">
                                                <input type="hidden" name="client_id" value="<?= h((string) $client['id']) ?>">
                                                <input type="hidden" name="status" value="ativo">
                                                <button type="submit">Reativar</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post">
                                                <input type="hidden" name="action" value="change_client_status">
                                                <input type="hidden" name="client_id" value="<?= h((string) $client['id']) ?>">
                                                <input type="hidden" name="status" value="cancelado">
                                                <button type="submit" class="btn-danger">Cancelar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>
    </div>
</body>

</html>
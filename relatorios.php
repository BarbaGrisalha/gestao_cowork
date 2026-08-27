<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('Europe/Lisbon');

if (!(bool) ($_SESSION['authenticated'] ?? false)) {
    header('Location: index.php');
    exit;
}

$dataDir = __DIR__ . '/data';
$bookingsFile = $dataDir . '/bookings.json';
$clientsFile = $dataDir . '/clients.json';

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function loadJsonArray(string $file): array
{
    if (!file_exists($file)) {
        return [];
    }

    $raw = file_get_contents($file);
    $decoded = $raw === false ? null : json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function clientDisplayName(array $client): string
{
    return trim((string) ($client['first_name'] ?? '') . ' ' . (string) ($client['last_name'] ?? ''));
}

function normalizeNif(string $nif): string
{
    return preg_replace('/\D+/', '', $nif) ?? '';
}

function bookingStatus(array $booking): string
{
    if (($booking['status'] ?? '') === 'cancelado') {
        return 'cancelado';
    }

    if (($booking['rental_type'] ?? '') === 'monthly') {
        return 'contratado';
    }

    return ($booking['status'] ?? '') === 'contratado' ? 'contratado' : 'reservado';
}

function bookingOccursOnDate(array $booking, DateTimeImmutable $date): bool
{
    if (!isset($booking['start'], $booking['end'])) {
        return false;
    }

    $dayStart = $date->setTime(0, 0);
    $dayEnd = $dayStart->modify('+1 day');
    $bookingStart = new DateTimeImmutable($booking['start']);
    $bookingEnd = new DateTimeImmutable($booking['end']);

    return $bookingStart < $dayEnd && $bookingEnd > $dayStart;
}

$spaces = [
    'sala_reuniao_grande' => 'Sala de Reuniao Grande',
    'sala_reuniao_pequena' => 'Sala de Reuniao Pequena',
    'sala_individual_1' => 'Sala Individual 1',
    'sala_individual_2' => 'Sala Individual 2',
    'sala_individual_3' => 'Sala Individual 3',
    'sala_individual_4' => 'Sala Individual 4',
    'sala_grupo_1' => 'Sala de Grupo 1',
    'sala_grupo_2' => 'Sala de Grupo 2',
    'sala_grupo_3' => 'Sala de Grupo 3',
    'open_space' => 'Open Space',
];

$bookings = loadJsonArray($bookingsFile);
$clients = loadJsonArray($clientsFile);
$clientsById = [];
foreach ($clients as $client) {
    if (isset($client['id'])) {
        $clientsById[(string) $client['id']] = $client;
    }
}

$filters = [
    'client' => trim((string) ($_GET['client'] ?? '')),
    'space' => trim((string) ($_GET['space'] ?? '')),
    'nif' => normalizeNif(trim((string) ($_GET['nif'] ?? ''))),
    'date_start' => trim((string) ($_GET['date_start'] ?? '')),
    'date_end' => trim((string) ($_GET['date_end'] ?? '')),
    'financial' => (string) ($_GET['financial'] ?? '') === '1',
];

$reportStart = null;
$reportEnd = null;
if ($filters['date_start'] !== '') {
    $reportStart = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['date_start']) ?: null;
}
if ($filters['date_end'] !== '') {
    $reportEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $filters['date_end']) ?: null;
}

$dateError = null;
if ($filters['date_start'] !== '' && $reportStart === null) {
    $dateError = 'Data inicial invalida.';
} elseif ($filters['date_end'] !== '' && $reportEnd === null) {
    $dateError = 'Data final invalida.';
} elseif ($reportStart !== null && $reportEnd !== null && $reportStart > $reportEnd) {
    $dateError = 'A data inicial deve ser anterior ou igual a data final.';
}

$results = [];
foreach ($bookings as $booking) {
    $client = $clientsById[(string) ($booking['client_id'] ?? '')] ?? [];
    $clientName = $client ? clientDisplayName($client) : (string) ($booking['client_name'] ?? '');
    $clientNif = normalizeNif((string) ($client['nif'] ?? ''));

    if ($filters['client'] !== '' && stripos($clientName, $filters['client']) === false) {
        continue;
    }

    if ($filters['space'] !== '' && ($booking['space'] ?? '') !== $filters['space']) {
        continue;
    }

    if ($filters['nif'] !== '' && $clientNif !== $filters['nif']) {
        continue;
    }

    if ($dateError !== null) {
        continue;
    }

    $bookingStart = new DateTimeImmutable($booking['start']);
    $bookingEnd = new DateTimeImmutable($booking['end']);
    if ($reportStart !== null && $bookingEnd <= $reportStart) {
        continue;
    }
    if ($reportEnd !== null && $bookingStart >= $reportEnd->modify('+1 day')) {
        continue;
    }

    if ($filters['financial'] && bookingStatus($booking) !== 'contratado') {
        continue;
    }

    $booking['_client_name'] = $clientName;
    $booking['_client_nif'] = $clientNif;
    $results[] = $booking;
}

usort($results, static function (array $a, array $b): int {
    return strcmp((string) ($a['start'] ?? ''), (string) ($b['start'] ?? ''));
});

$financialSubtotal = 0.0;
$financialVat = 0.0;
$financialTotal = 0.0;
foreach ($results as $booking) {
    if (bookingStatus($booking) === 'contratado') {
        $financialSubtotal += (float) ($booking['subtotal'] ?? 0);
        $financialVat += (float) ($booking['vat'] ?? 0);
        $financialTotal += (float) ($booking['total'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatorios - Gestao Cowork</title>
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
            width: min(1240px, 94vw);
            margin: 28px auto;
        }

        .panel {
            border: 1px solid var(--line);
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.02), transparent), var(--panel);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0 0 8px;
            font: 700 28px 'Playfair Display', serif;
            color: var(--gold);
        }

        p {
            margin: 0;
            color: var(--muted);
        }

        .links {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-ghost {
            color: var(--text);
            text-decoration: none;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 14px;
            font-size: 14px;
        }

        .filters {
            display: grid;
            grid-template-columns: 1.4fr 1.2fr 1fr 1fr 1fr auto;
            gap: 12px;
            align-items: end;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card);
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        label {
            color: var(--muted);
            font-size: 13px;
        }

        input,
        select,
        button {
            border: 1px solid var(--line);
            border-radius: 9px;
            padding: 11px;
            font: inherit;
        }

        input,
        select {
            background: #122028;
            color: var(--text);
            min-width: 0;
        }

        button {
            background: linear-gradient(135deg, var(--gold), #b48d49);
            color: #0f1a1f;
            font-weight: 700;
            cursor: pointer;
        }

        .summary {
            margin: 18px 0 8px;
            color: var(--muted);
            font-size: 14px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        th,
        td {
            text-align: left;
            border-bottom: 1px solid #304854;
            padding: 11px 8px;
            font-size: 13px;
            vertical-align: top;
        }

        th {
            color: var(--muted);
            font-weight: 500;
        }

        .empty {
            padding: 22px 0;
            color: var(--muted);
        }

        .money {
            white-space: nowrap;
        }

        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .status-reservado {
            color: #e8c981;
            background: rgba(201, 169, 110, 0.15);
        }

        .status-contratado {
            color: #78c99e;
            background: rgba(93, 179, 139, 0.15);
        }

        .status-cancelado {
            color: #ef9797;
            background: rgba(213, 111, 111, 0.15);
        }

        .finance-box {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin: 18px 0 8px;
            padding: 14px;
            border: 1px solid rgba(93, 179, 139, 0.35);
            border-radius: 10px;
            background: rgba(93, 179, 139, 0.08);
            color: var(--muted);
            font-size: 13px;
        }

        .finance-box strong {
            color: var(--text);
        }

        @media (max-width: 900px) {
            .filters {
                grid-template-columns: repeat(2, 1fr);
            }

            .filters .field:last-child {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 560px) {
            .filters {
                grid-template-columns: 1fr;
            }

            .filters .field:last-child {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <section class="panel">
            <div class="topbar">
                <div>
                    <h1>Relatorios de Agendamentos</h1>
                    <p>Consulte o historico por cliente, sala/espaco, NIF ou data.</p>
                </div>
                <div class="links">
                    <a class="btn-ghost" href="index.php">Retorno a Locações</a>
                    <a class="btn-ghost" href="clientes.php">Clientes</a>
                    <a class="btn-ghost" href="?financial=1" style="border-color:var(--gold); color:var(--gold);">Financeiro / Faturamento</a>
                </div>
            </div>

            <form class="filters" method="get">
                <?php if ($filters['financial']): ?>
                    <input type="hidden" name="financial" value="1">
                <?php endif; ?>
                <div class="field">
                    <label for="client">Nome + apelido</label>
                    <input id="client" name="client" type="search" placeholder="Ex.: Ana Silva" value="<?= h($filters['client']) ?>">
                </div>
                <div class="field">
                    <label for="space">Sala / espaco</label>
                    <select id="space" name="space">
                        <option value="">Todos os espacos</option>
                        <?php foreach ($spaces as $spaceKey => $spaceLabel): ?>
                            <option value="<?= h($spaceKey) ?>" <?= $filters['space'] === $spaceKey ? 'selected' : '' ?>><?= h($spaceLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="nif">NIF</label>
                    <input id="nif" name="nif" inputmode="numeric" value="<?= h($filters['nif']) ?>" placeholder="123456789">
                </div>
                <div class="field">
                    <label for="date_start">Data inicial</label>
                    <input id="date_start" name="date_start" type="date" value="<?= h($filters['date_start']) ?>">
                </div>
                <div class="field">
                    <label for="date_end">Data final</label>
                    <input id="date_end" name="date_end" type="date" value="<?= h($filters['date_end']) ?>">
                </div>
                <div class="field">
                    <button type="submit">Filtrar</button>
                </div>
            </form>

            <?php if ($dateError !== null): ?>
                <div class="summary" style="color:#ef9797;"><?= h($dateError) ?></div>
            <?php endif; ?>

            <?php if ($filters['financial']): ?>
                <div class="finance-box">
                    <span>Registos faturaveis: <strong><?= count($results) ?></strong></span>
                    <span>Subtotal: <strong><?= number_format($financialSubtotal, 2, ',', '.') ?> EUR</strong></span>
                    <span>IVA: <strong><?= number_format($financialVat, 2, ',', '.') ?> EUR</strong></span>
                    <span>Total: <strong><?= number_format($financialTotal, 2, ',', '.') ?> EUR</strong></span>
                </div>
            <?php endif; ?>

            <div class="summary"><?= count($results) ?> agendamento(s) encontrado(s)<?= $filters['financial'] ? ' no faturamento' : '' ?>.</div>

            <?php if (!$results): ?>
                <div class="empty">Nenhum agendamento corresponde aos filtros.</div>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>NIF</th>
                                <th>E-mail</th>
                                <th>Telefone</th>
                                <th>Sala / espaco</th>
                                <th>Tipo</th>
                                <th>Status</th>
                                <th>Confirmacao de uso</th>
                                <th>Faturamento</th>
                                <th>Inicio</th>
                                <th>Fim</th>
                                <th>Subtotal</th>
                                <th>IVA</th>
                                <th>Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $booking): ?>
                                <?php $client = $clientsById[(string) ($booking['client_id'] ?? '')] ?? []; ?>
                                <tr>
                                    <td><?= h((string) $booking['_client_name']) ?></td>
                                    <td><?= h((string) $booking['_client_nif']) ?></td>
                                    <td><?= h((string) ($client['email'] ?? '')) ?></td>
                                    <td><?= h((string) ($client['phone'] ?? '')) ?></td>
                                    <td><?= h((string) ($booking['space_label'] ?? $spaces[$booking['space'] ?? ''] ?? '')) ?></td>
                                    <td><?= h((string) ($booking['rental_type_label'] ?? '')) ?></td>
                                    <?php $status = bookingStatus($booking); ?>
                                    <td><span class="status status-<?= h($status) ?>"><?= h($status) ?></span></td>
                                    <td><?= h(($booking['usage_confirmation'] ?? ($status === 'contratado' ? 'sim' : 'pendente')) === 'sim' ? 'Sim' : (($booking['usage_confirmation'] ?? '') === 'nao' ? 'Nao' : 'Pendente')) ?></td>
                                    <td><?= $status === 'contratado' ? 'Sim' : 'Nao' ?></td>
                                    <td><?= h((new DateTimeImmutable($booking['start']))->format('d/m/Y H:i')) ?></td>
                                    <td><?= h((new DateTimeImmutable($booking['end']))->format('d/m/Y H:i')) ?></td>
                                    <td class="money"><?= number_format((float) ($booking['subtotal'] ?? 0), 2, ',', '.') ?> EUR</td>
                                    <td class="money"><?= number_format((float) ($booking['vat'] ?? 0), 2, ',', '.') ?> EUR</td>
                                    <td class="money"><?= number_format((float) ($booking['total'] ?? 0), 2, ',', '.') ?> EUR</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</body>

</html>
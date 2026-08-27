<?php

declare(strict_types=1);

session_start();

date_default_timezone_set('Europe/Lisbon');

const VAT_RATE = 0.23;
const HOURLY_PRICE = 10.0;

const DAILY_PRICE = 65.0;
const WEEKLY_PRICE = 280.0;
const MONTHLY_PRICE = 920.0;

const WORK_START = '08:30';
const WORK_END = '19:30';

$spaces = [
    'sala_reuniao_grande' => ['label' => 'Sala de Reuniao Grande', 'capacity' => 1],
    'sala_reuniao_pequena' => ['label' => 'Sala de Reuniao Pequena', 'capacity' => 1],
    'sala_individual_1' => ['label' => 'Sala Individual 1', 'capacity' => 1],
    'sala_individual_2' => ['label' => 'Sala Individual 2', 'capacity' => 1],
    'sala_individual_3' => ['label' => 'Sala Individual 3', 'capacity' => 1],
    'sala_individual_4' => ['label' => 'Sala Individual 4', 'capacity' => 1],
    'sala_grupo_1' => ['label' => 'Sala de Grupo 1', 'capacity' => 1],
    'sala_grupo_2' => ['label' => 'Sala de Grupo 2', 'capacity' => 1],
    'sala_grupo_3' => ['label' => 'Sala de Grupo 3', 'capacity' => 1],
    'open_space' => ['label' => 'Open Space', 'capacity' => 20],
];

$rentalTypeLabels = [
    'hourly' => 'Hora',
    'daily' => 'Diaria',
    'weekly' => 'Semanal',
    'monthly' => 'Mensal',
];

$dataDir = __DIR__ . '/data';
$bookingsFile = $dataDir . '/bookings.json';
$clientsFile = $dataDir . '/clients.json';

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

if (!file_exists($bookingsFile)) {
    file_put_contents($bookingsFile, json_encode([], JSON_PRETTY_PRINT));
}

if (!file_exists($clientsFile)) {
    file_put_contents($clientsFile, json_encode([], JSON_PRETTY_PRINT));
}

function loadBookings(string $bookingsFile): array
{
    $raw = file_get_contents($bookingsFile);
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function saveBookings(string $bookingsFile, array $bookings): bool
{
    $json = json_encode($bookings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }

    return file_put_contents($bookingsFile, $json, LOCK_EX) !== false;
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

function clientDisplayName(array $client): string
{
    $first = trim((string) ($client['first_name'] ?? ''));
    $last = trim((string) ($client['last_name'] ?? ''));
    return trim($first . ' ' . $last);
}

function clientStatus(array $client, ?DateTimeImmutable $now = null): string
{
    if (($client['status'] ?? '') === 'cancelado') {
        return 'cancelado';
    }

    $now ??= new DateTimeImmutable('now');
    $lastUpdatedRaw = (string) ($client['last_updated_at'] ?? $client['created_at'] ?? '');
    $lastUpdated = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $lastUpdatedRaw);

    return $lastUpdated !== false && $lastUpdated->modify('+12 months') > $now ? 'ativo' : 'pendente_atualizacao';
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

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function isWeekday(DateTimeImmutable $dt): bool
{
    $n = (int) $dt->format('N');
    return $n >= 1 && $n <= 5;
}

function timeToMinutes(string $hhmm): int
{
    [$h, $m] = explode(':', $hhmm);
    return ((int) $h * 60) + (int) $m;
}

function isWithinBusinessHours(DateTimeImmutable $dt): bool
{
    $minutes = ((int) $dt->format('H') * 60) + (int) $dt->format('i');
    return $minutes >= timeToMinutes(WORK_START) && $minutes <= timeToMinutes(WORK_END);
}

function addBusinessDays(DateTimeImmutable $date, int $days): DateTimeImmutable
{
    $result = $date;
    $remaining = $days;
    while ($remaining > 0) {
        $result = $result->modify('+1 day');
        if (isWeekday($result)) {
            $remaining--;
        }
    }

    return $result;
}

function normalizeInterval(string $type, DateTimeImmutable $start, ?DateTimeImmutable $end): array
{
    if ($type === 'hourly') {
        if ($end === null) {
            throw new RuntimeException('Para locacao por hora, informe a data/hora final.');
        }
        return [$start, $end];
    }

    $startDate = $start->setTime(8, 30);

    if ($type === 'daily') {
        $endDate = $startDate->setTime(19, 30);
        return [$startDate, $endDate];
    }

    if ($type === 'weekly') {
        $last = addBusinessDays($startDate, 4)->setTime(19, 30);
        return [$startDate, $last];
    }

    if ($type === 'monthly') {
        $last = addBusinessDays($startDate, 19)->setTime(19, 30);
        return [$startDate, $last];
    }

    throw new RuntimeException('Tipo de locacao invalido.');
}

function overlaps(DateTimeImmutable $aStart, DateTimeImmutable $aEnd, DateTimeImmutable $bStart, DateTimeImmutable $bEnd): bool
{
    return $aStart < $bEnd && $bStart < $aEnd;
}

function maxConcurrentWithCandidate(array $spaceBookings, DateTimeImmutable $start, DateTimeImmutable $end): int
{
    $events = [];

    $events[] = ['ts' => (int) $start->format('U'), 'delta' => 1];
    $events[] = ['ts' => (int) $end->format('U'), 'delta' => -1];

    foreach ($spaceBookings as $b) {
        $bStart = new DateTimeImmutable($b['start']);
        $bEnd = new DateTimeImmutable($b['end']);

        if (!overlaps($start, $end, $bStart, $bEnd)) {
            continue;
        }

        $events[] = ['ts' => (int) $bStart->format('U'), 'delta' => 1];
        $events[] = ['ts' => (int) $bEnd->format('U'), 'delta' => -1];
    }

    usort($events, static function (array $a, array $b): int {
        if ($a['ts'] === $b['ts']) {
            return $a['delta'] <=> $b['delta'];
        }
        return $a['ts'] <=> $b['ts'];
    });

    $current = 0;
    $max = 0;
    foreach ($events as $event) {
        $current += $event['delta'];
        if ($current > $max) {
            $max = $current;
        }
    }

    return $max;
}

function computeBasePrice(string $type, DateTimeImmutable $start, DateTimeImmutable $end): float
{
    if ($type === 'hourly') {
        $seconds = $end->getTimestamp() - $start->getTimestamp();
        $hours = $seconds / 3600;
        return round($hours * HOURLY_PRICE, 2);
    }

    return match ($type) {
        'daily' => DAILY_PRICE,
        'weekly' => WEEKLY_PRICE,
        'monthly' => MONTHLY_PRICE,
        default => 0.0,
    };
}

function toDatetimeLocalValue(DateTimeImmutable $dt): string
{
    return $dt->format('Y-m-d\\TH:i');
}

$errors = [];
$success = null;

$loginUser = 'admin';
$loginPass = 'cowork2026';

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

if (($_POST['action'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === $loginUser && $password === $loginPass) {
        $_SESSION['authenticated'] = true;
        header('Location: index.php');
        exit;
    }

    $errors[] = 'Credenciais invalidas.';
}

$isAuthenticated = (bool) ($_SESSION['authenticated'] ?? false);
$bookings = loadBookings($bookingsFile);
$clients = loadClients($clientsFile);
$clientsById = [];
foreach ($clients as $client) {
    if (isset($client['id']) && clientStatus($client) === 'ativo') {
        $clientsById[$client['id']] = $client;
    }
}

if ($isAuthenticated && ($_POST['action'] ?? '') === 'create_booking') {
    $clientId = trim((string) ($_POST['client_id'] ?? ''));
    $space = (string) ($_POST['space'] ?? '');
    $rentalType = (string) ($_POST['rental_type'] ?? '');
    $startRaw = (string) ($_POST['start_datetime'] ?? '');
    $endRaw = trim((string) ($_POST['end_datetime'] ?? ''));

    if ($clientId === '' || !isset($clientsById[$clientId])) {
        $errors[] = 'Selecione um cliente ativo a partir do cadastro.';
    }

    if (!isset($spaces[$space])) {
        $errors[] = 'Espaco invalido.';
    }

    if (!isset($rentalTypeLabels[$rentalType])) {
        $errors[] = 'Tipo de locacao invalido.';
    }

    $start = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $startRaw) ?: null;
    $end = null;
    if ($endRaw !== '') {
        $end = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $endRaw) ?: null;
    }

    if ($start === null) {
        $errors[] = 'Data/hora inicial invalida.';
    }

    if ($rentalType === 'hourly' && $end === null) {
        $errors[] = 'Data/hora final invalida para locacao por hora.';
    }

    if (!$errors && $start !== null) {
        try {
            [$normalizedStart, $normalizedEnd] = normalizeInterval($rentalType, $start, $end);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
            $normalizedStart = null;
            $normalizedEnd = null;
        }
    }

    if (!$errors && isset($normalizedStart, $normalizedEnd)) {
        $now = new DateTimeImmutable('now');

        if ($normalizedStart <= $now) {
            $errors[] = 'Nao e permitido agendar para data/hora passada ou atual.';
        }

        if ($normalizedEnd <= $normalizedStart) {
            $errors[] = 'A data/hora final deve ser maior que a inicial.';
        }

        if (!isWeekday($normalizedStart) || !isWeekday($normalizedEnd)) {
            $errors[] = 'As locacoes so podem ocorrer de segunda a sexta-feira.';
        }

        if (!isWithinBusinessHours($normalizedStart) || !isWithinBusinessHours($normalizedEnd)) {
            $errors[] = 'Horario permitido: 08:30h as 19:30h.';
        }

        if ($rentalType === 'hourly' && $normalizedStart->format('Y-m-d') !== $normalizedEnd->format('Y-m-d')) {
            $errors[] = 'Locacao por hora deve iniciar e terminar no mesmo dia.';
        }

        if ($rentalType !== 'hourly') {
            $cursor = $normalizedStart;
            while ($cursor <= $normalizedEnd) {
                if (!isWeekday($cursor)) {
                    $errors[] = 'Locacao diaria/semanal/mensal nao pode incluir fim de semana.';
                    break;
                }
                $cursor = $cursor->modify('+1 day');
            }
        }

        $sameSpaceBookings = array_values(array_filter(
            $bookings,
            static fn(array $b): bool => ($b['space'] ?? '') === $space && bookingStatus($b) !== 'cancelado'
        ));

        $lockedHourlyBookings = array_values(array_filter(
            $sameSpaceBookings,
            static fn(array $b): bool => ($b['rental_type'] ?? '') === 'hourly' && bookingStatus($b) === 'contratado'
        ));
        $availablePeriodBookings = array_values(array_filter(
            $sameSpaceBookings,
            static fn(array $b): bool => !in_array($b, $lockedHourlyBookings, true)
        ));

        $maxConcurrent = count($lockedHourlyBookings) + maxConcurrentWithCandidate($availablePeriodBookings, $normalizedStart, $normalizedEnd);
        if ($maxConcurrent > $spaces[$space]['capacity']) {
            if ($space === 'open_space') {
                $errors[] = 'Limite do Open Space excedido. Maximo de 20 pessoas simultaneas.';
            } else {
                $errors[] = 'Este espaco ja possui agendamento no periodo selecionado.';
            }
        }

        if (!$errors) {
            $selectedClient = $clientsById[$clientId];
            $selectedClientName = clientDisplayName($selectedClient);
            $subtotal = computeBasePrice($rentalType, $normalizedStart, $normalizedEnd);
            $vatValue = round($subtotal * VAT_RATE, 2);
            $total = round($subtotal + $vatValue, 2);

            $bookings[] = [
                'id' => bin2hex(random_bytes(8)),
                'client_id' => $clientId,
                'client_name' => $selectedClientName,
                'space' => $space,
                'space_label' => $spaces[$space]['label'],
                'rental_type' => $rentalType,
                'rental_type_label' => $rentalTypeLabels[$rentalType],
                'status' => $rentalType === 'monthly' ? 'contratado' : 'reservado',
                'usage_confirmation' => $rentalType === 'monthly' ? 'sim' : 'pendente',
                'start' => $normalizedStart->format(DateTimeInterface::ATOM),
                'end' => $normalizedEnd->format(DateTimeInterface::ATOM),
                'subtotal' => $subtotal,
                'vat' => $vatValue,
                'total' => $total,
                'created_at' => (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            ];

            usort($bookings, static function (array $a, array $b): int {
                return strcmp($a['start'], $b['start']);
            });

            if (saveBookings($bookingsFile, $bookings)) {
                $success = 'Locacao criada com sucesso.';
            } else {
                $errors[] = 'Falha ao salvar locacao.';
            }
        }
    }
}

if ($isAuthenticated && ($_POST['action'] ?? '') === 'resolve_hourly_booking') {
    $bookingId = trim((string) ($_POST['booking_id'] ?? ''));
    $decision = (string) ($_POST['decision'] ?? '');

    foreach ($bookings as $bookingIndex => $booking) {
        if (($booking['id'] ?? '') !== $bookingId || ($booking['rental_type'] ?? '') !== 'hourly') {
            continue;
        }

        if ($decision === 'yes') {
            $bookings[$bookingIndex]['status'] = 'contratado';
            $bookings[$bookingIndex]['usage_confirmation'] = 'sim';
            $success = 'Reserva horaria marcada como contratado.';
        } elseif ($decision === 'no') {
            $bookings[$bookingIndex]['status'] = 'reservado';
            $bookings[$bookingIndex]['usage_confirmation'] = 'nao';
            $success = 'Reserva mantida como reservado. O espaco ficou disponivel para nova locacao.';
        } else {
            $errors[] = 'Decisao invalida para a reserva horaria.';
            break;
        }

        if (!saveBookings($bookingsFile, $bookings)) {
            $errors[] = 'Falha ao atualizar a reserva horaria.';
            $success = null;
        }
        break;
    }
}

if ($isAuthenticated && ($_POST['action'] ?? '') === 'cancel_booking') {
    $bookingId = trim((string) ($_POST['booking_id'] ?? ''));
    foreach ($bookings as $bookingIndex => $booking) {
        if (($booking['id'] ?? '') !== $bookingId) {
            continue;
        }

        $bookings[$bookingIndex]['status'] = 'cancelado';
        $bookings[$bookingIndex]['usage_confirmation'] = 'nao';
        if (saveBookings($bookingsFile, $bookings)) {
            $success = 'Reserva cancelada. O espaco ficou disponivel.';
        } else {
            $errors[] = 'Falha ao cancelar a reserva.';
        }
        break;
    }
}

$bookingsBySpace = [];
$today = new DateTimeImmutable('today');
$now = new DateTimeImmutable('now');
$pendingHourlyBookings = [];
foreach ($bookings as $booking) {
    if (($booking['rental_type'] ?? '') === 'hourly'
        && bookingStatus($booking) === 'reservado'
        && isset($booking['end'])
        && new DateTimeImmutable($booking['end']) <= $now
    ) {
        $pendingHourlyBookings[] = $booking;
    }
}
foreach ($spaces as $spaceKey => $spaceInfo) {
    $bookingsBySpace[$spaceKey] = array_values(array_filter(
        $bookings,
        static function (array $b) use ($spaceKey, $today): bool {
            if (($b['space'] ?? '') !== $spaceKey || !isset($b['end'])) {
                return false;
            }

            return bookingStatus($b) !== 'cancelado' && new DateTimeImmutable($b['end']) > $today;
        }
    ));
}

$nowDefault = (new DateTimeImmutable('now'))->modify('+30 minutes');
$roundedTimestamp = (int) (ceil($nowDefault->getTimestamp() / 300) * 300);
$nowDefault = (new DateTimeImmutable('now'))->setTimestamp($roundedTimestamp);
$defaultStartValue = toDatetimeLocalValue($nowDefault);
$defaultEndValue = toDatetimeLocalValue($nowDefault->modify('+1 hour'));
$activeClients = array_values(array_filter($clients, static fn(array $client): bool => clientStatus($client) === 'ativo'));
$hasClients = count($activeClients) > 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestao Cowork</title>
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
            background: radial-gradient(circle at 10% 10%, #1f3038 0%, var(--bg) 45%);
            color: var(--text);
            min-height: 100vh;
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

        p {
            margin: 0;
            color: var(--muted);
        }

        .login-box {
            max-width: 420px;
            margin: 40px auto;
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
        select,
        button {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px;
            font: inherit;
        }

        input,
        select {
            background: #122028;
            color: var(--text);
        }

        button {
            background: linear-gradient(135deg, var(--gold), #b48d49);
            color: #0f1a1f;
            font-weight: 700;
            cursor: pointer;
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

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            flex-wrap: wrap;
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

        .muted {
            color: var(--muted);
            font-size: 13px;
        }

        .cards {
            margin-top: 20px;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .agenda-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--card);
            padding: 14px;
        }

        .agenda-card h3 {
            margin: 0 0 8px;
            font-size: 16px;
            color: var(--gold);
        }

        .chip {
            display: inline-block;
            font-size: 12px;
            padding: 3px 8px;
            border-radius: 30px;
            border: 1px solid var(--line);
            color: var(--muted);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        th,
        td {
            text-align: left;
            border-bottom: 1px solid #304854;
            padding: 8px 6px;
            font-size: 13px;
        }

        th {
            color: var(--muted);
            font-weight: 500;
        }

        .empty {
            color: var(--muted);
            font-size: 13px;
            margin-top: 8px;
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

        .alert-overlay {
            position: fixed;
            inset: 0;
            z-index: 10;
            display: grid;
            place-items: center;
            padding: 20px;
            background: rgba(5, 10, 12, 0.78);
        }

        .alert-box {
            width: min(620px, 100%);
            max-height: 90vh;
            overflow-y: auto;
            padding: 24px;
            border: 1px solid var(--gold);
            border-radius: 14px;
            background: var(--panel);
            box-shadow: 0 24px 80px rgba(0, 0, 0, 0.5);
        }

        .alert-box h2 {
            margin-bottom: 8px;
        }

        .pending-booking {
            display: grid;
            gap: 6px;
            margin-top: 16px;
            padding: 14px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: var(--card);
        }

        .pending-booking span {
            color: var(--muted);
            font-size: 13px;
        }

        .alert-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 8px;
        }

        .alert-actions button {
            padding: 9px 12px;
            font-size: 13px;
        }

        .alert-actions .btn-danger {
            color: var(--text);
            background: transparent;
            border-color: var(--err);
        }

        @media (max-width: 980px) {

            .form-grid,
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="wrap">
        <?php if (!$isAuthenticated): ?>
            <section class="panel login-box">
                <h1>Gestao Cowork</h1>
                <p>Acesso administrativo protegido por login</p>

                <?php foreach ($errors as $error): ?>
                    <div class="notice err"><?= h($error) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="login">
                    <div class="form-grid">
                        <div class="field full">
                            <label for="username">Utilizador</label>
                            <input id="username" name="username" required placeholder="admin">
                        </div>
                        <div class="field full">
                            <label for="password">Palavra-passe</label>
                            <input id="password" name="password" type="password" required placeholder="******">
                        </div>
                        <div class="field full">
                            <button type="submit">Entrar</button>
                        </div>
                    </div>
                </form>

                <p class="muted" style="margin-top:12px;">Credenciais iniciais: admin / cowork2026</p>
            </section>
        <?php else: ?>
            <section class="panel">
                <div class="topbar">
                    <div>
                        <h1>Painel de Administracao</h1>
                        <p>Reservas de hoje (<?= h($today->format('d/m/Y')) ?>) em diante · segunda a sexta, das 08:30h as 19:30h</p>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <a class="btn-ghost" href="clientes.php">Cadastro de Clientes</a>
                        <a class="btn-ghost" href="relatorios.php" style="border-color:var(--gold); color:var(--gold);">Relatorios (inclui historico)</a>
                        <a class="btn-ghost" href="?logout=1">Terminar sessao</a>
                    </div>
                </div>

                <?php if ($success !== null): ?>
                    <div class="notice ok"><?= h($success) ?></div>
                <?php endif; ?>

                <?php foreach ($errors as $error): ?>
                    <div class="notice err"><?= h($error) ?></div>
                <?php endforeach; ?>

                <?php if (!$hasClients): ?>
                    <div class="notice err">Nao ha clientes cadastrados. Cadastre primeiro em "Cadastro de Clientes".</div>
                <?php endif; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="create_booking">

                    <div class="form-grid">
                        <div class="field full">
                            <label for="client_filter">Cliente (buscar por nome + apelido)</label>
                            <input id="client_filter" type="search" placeholder="Digite nome ou apelido para filtrar" <?= $hasClients ? '' : 'disabled' ?>>
                        </div>

                        <div class="field full">
                            <label for="client_id">Selecionar cliente registado</label>
                            <select id="client_id" name="client_id" required <?= $hasClients ? '' : 'disabled' ?>>
                                <option value="">Selecione...</option>
                                <?php foreach ($activeClients as $client): ?>
                                    <?php $displayName = clientDisplayName($client); ?>
                                    <option value="<?= h((string) $client['id']) ?>"><?= h($displayName) ?> · NIF <?= h((string) ($client['nif'] ?? '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="space">Espaco</label>
                            <select id="space" name="space" required>
                                <?php foreach ($spaces as $spaceKey => $spaceInfo): ?>
                                    <option value="<?= h($spaceKey) ?>"><?= h($spaceInfo['label']) ?> (Capacidade: <?= (int) $spaceInfo['capacity'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="field">
                            <label for="rental_type">Tipo de locacao</label>
                            <select id="rental_type" name="rental_type" required>
                                <option value="hourly">Hora (10.00 EUR/h + IVA)</option>
                                <option value="daily">Diaria (65.00 EUR + IVA)</option>
                                <option value="weekly">Semanal (280.00 EUR + IVA)</option>
                                <option value="monthly">Mensal (920.00 EUR + IVA)</option>
                            </select>
                        </div>

                        <div class="field">
                            <label for="start_datetime">Inicio</label>
                            <input id="start_datetime" name="start_datetime" type="datetime-local" required value="<?= h($defaultStartValue) ?>">
                        </div>

                        <div class="field">
                            <label for="end_datetime">Fim (apenas por hora)</label>
                            <input id="end_datetime" name="end_datetime" type="datetime-local" value="<?= h($defaultEndValue) ?>">
                        </div>

                        <div class="field full">
                            <button type="submit" <?= $hasClients ? '' : 'disabled' ?>>Registar locacao</button>
                        </div>
                    </div>
                </form>

                <p class="muted" style="margin-top: 14px;">
                    Regras: nao permite data/hora passada ou atual; salas de reuniao, individuais e de grupo aceitam apenas 1 agendamento simultaneo; Open Space suporta ate 20 pessoas em paralelo.
                </p>
            </section>

            <section class="cards">
                <?php foreach ($spaces as $spaceKey => $spaceInfo): ?>
                    <article class="agenda-card">
                        <h3><?= h($spaceInfo['label']) ?></h3>
                        <span class="chip">Capacidade simultanea: <?= (int) $spaceInfo['capacity'] ?></span>

                        <?php if (empty($bookingsBySpace[$spaceKey])): ?>
                            <div class="empty">Sem agendamentos.</div>
                        <?php else: ?>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Tipo</th>
                                        <th>Periodo</th>
                                        <th>Status</th>
                                        <th>Confirmacao</th>
                                        <th>Acao</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($bookingsBySpace[$spaceKey] as $booking): ?>
                                        <?php
                                        $startView = (new DateTimeImmutable($booking['start']))->format('d/m/Y H:i');
                                        $endView = (new DateTimeImmutable($booking['end']))->format('d/m/Y H:i');
                                        ?>
                                        <tr>
                                            <td><?= h($booking['client_name']) ?></td>
                                            <td><?= h($booking['rental_type_label']) ?></td>
                                            <td><?= h($startView) ?> - <?= h($endView) ?></td>
                                            <td><span class="status status-<?= h(bookingStatus($booking)) ?>"><?= h(bookingStatus($booking)) ?></span></td>
                                            <td><?= h(($booking['usage_confirmation'] ?? 'pendente') === 'sim' ? 'Sim' : (($booking['usage_confirmation'] ?? '') === 'nao' ? 'Nao' : 'Pendente')) ?></td>
                                            <td>
                                                <?php if (bookingStatus($booking) !== 'cancelado'): ?>
                                                    <form method="post">
                                                        <input type="hidden" name="action" value="cancel_booking">
                                                        <input type="hidden" name="booking_id" value="<?= h((string) $booking['id']) ?>">
                                                        <button type="submit" class="btn-danger">Cancelar</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="muted">Cancelado</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
            <p class="muted" style="margin-top: 16px;">O painel mostra reservas de hoje e futuras. Use <a href="relatorios.php" style="color:var(--gold);">Relatorios (inclui historico)</a> para consultar locacoes passadas.</p>

            <?php if ($pendingHourlyBookings): ?>
                <div class="alert-overlay" role="dialog" aria-modal="true" aria-labelledby="hourlyAlertTitle">
                    <div class="alert-box">
                        <h2 id="hourlyAlertTitle">Confirmacao de reservas horarias</h2>
                        <p>O horario destas reservas terminou. O cliente pretende contratar o espaco?</p>
                        <?php foreach ($pendingHourlyBookings as $pendingBooking): ?>
                            <div class="pending-booking">
                                <strong><?= h($pendingBooking['client_name']) ?></strong>
                                <span><?= h($pendingBooking['space_label'] ?? '') ?> · terminou em <?= h((new DateTimeImmutable($pendingBooking['end']))->format('d/m/Y H:i')) ?></span>
                                <div class="alert-actions">
                                    <form method="post">
                                        <input type="hidden" name="action" value="resolve_hourly_booking">
                                        <input type="hidden" name="booking_id" value="<?= h((string) $pendingBooking['id']) ?>">
                                        <input type="hidden" name="decision" value="yes">
                                        <button type="submit">Contratado: Sim</button>
                                    </form>
                                    <form method="post">
                                        <input type="hidden" name="action" value="resolve_hourly_booking">
                                        <input type="hidden" name="booking_id" value="<?= h((string) $pendingBooking['id']) ?>">
                                        <input type="hidden" name="decision" value="no">
                                        <button type="submit" class="btn-danger">Contratado: Nao</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        (function() {
            const rentalType = document.getElementById('rental_type');
            const endField = document.getElementById('end_datetime');
            if (!rentalType || !endField) {
                return;
            }

            function toggleEndField() {
                const isHourly = rentalType.value === 'hourly';
                endField.disabled = !isHourly;
                endField.required = isHourly;
            }

            rentalType.addEventListener('change', toggleEndField);
            toggleEndField();
        })();

        (function() {
            const filterInput = document.getElementById('client_filter');
            const select = document.getElementById('client_id');
            if (!filterInput || !select) {
                return;
            }

            const options = Array.from(select.options).map((opt) => ({
                value: opt.value,
                text: opt.textContent || ''
            }));

            function applyFilter() {
                const query = filterInput.value.trim().toLowerCase();
                const previous = select.value;

                select.innerHTML = '';
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Selecione...';
                select.appendChild(placeholder);

                options.forEach((item) => {
                    if (item.value === '') {
                        return;
                    }
                    if (query && !item.text.toLowerCase().includes(query)) {
                        return;
                    }
                    const opt = document.createElement('option');
                    opt.value = item.value;
                    opt.textContent = item.text;
                    select.appendChild(opt);
                });

                if (Array.from(select.options).some((o) => o.value === previous)) {
                    select.value = previous;
                }
            }

            filterInput.addEventListener('input', applyFilter);
        })();
    </script>
</body>

</html>
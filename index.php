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

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

if (!file_exists($bookingsFile)) {
    file_put_contents($bookingsFile, json_encode([], JSON_PRETTY_PRINT));
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

if ($isAuthenticated && ($_POST['action'] ?? '') === 'create_booking') {
    $clientName = trim((string) ($_POST['client_name'] ?? ''));
    $space = (string) ($_POST['space'] ?? '');
    $rentalType = (string) ($_POST['rental_type'] ?? '');
    $startRaw = (string) ($_POST['start_datetime'] ?? '');
    $endRaw = trim((string) ($_POST['end_datetime'] ?? ''));

    if ($clientName === '') {
        $errors[] = 'Informe o nome do cliente.';
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
            static fn(array $b): bool => ($b['space'] ?? '') === $space
        ));

        $maxConcurrent = maxConcurrentWithCandidate($sameSpaceBookings, $normalizedStart, $normalizedEnd);
        if ($maxConcurrent > $spaces[$space]['capacity']) {
            if ($space === 'open_space') {
                $errors[] = 'Limite do Open Space excedido. Maximo de 20 pessoas simultaneas.';
            } else {
                $errors[] = 'Este espaco ja possui agendamento no periodo selecionado.';
            }
        }

        if (!$errors) {
            $subtotal = computeBasePrice($rentalType, $normalizedStart, $normalizedEnd);
            $vatValue = round($subtotal * VAT_RATE, 2);
            $total = round($subtotal + $vatValue, 2);

            $bookings[] = [
                'id' => bin2hex(random_bytes(8)),
                'client_name' => $clientName,
                'space' => $space,
                'space_label' => $spaces[$space]['label'],
                'rental_type' => $rentalType,
                'rental_type_label' => $rentalTypeLabels[$rentalType],
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

$bookingsBySpace = [];
foreach ($spaces as $spaceKey => $spaceInfo) {
    $bookingsBySpace[$spaceKey] = array_values(array_filter(
        $bookings,
        static fn(array $b): bool => ($b['space'] ?? '') === $spaceKey
    ));
}

$nowDefault = (new DateTimeImmutable('now'))->modify('+30 minutes');
$roundedTimestamp = (int) (ceil($nowDefault->getTimestamp() / 300) * 300);
$nowDefault = (new DateTimeImmutable('now'))->setTimestamp($roundedTimestamp);
$defaultStartValue = toDatetimeLocalValue($nowDefault);
$defaultEndValue = toDatetimeLocalValue($nowDefault->modify('+1 hour'));
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
                        <p>Locacoes: segunda a sexta, das 08:30h as 19:30h</p>
                    </div>
                    <a class="btn-ghost" href="?logout=1">Terminar sessao</a>
                </div>

                <?php if ($success !== null): ?>
                    <div class="notice ok"><?= h($success) ?></div>
                <?php endif; ?>

                <?php foreach ($errors as $error): ?>
                    <div class="notice err"><?= h($error) ?></div>
                <?php endforeach; ?>

                <form method="post" autocomplete="off">
                    <input type="hidden" name="action" value="create_booking">

                    <div class="form-grid">
                        <div class="field full">
                            <label for="client_name">Cliente</label>
                            <input id="client_name" name="client_name" required placeholder="Nome do cliente">
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
                            <button type="submit">Registar locacao</button>
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
                                        <th>Total</th>
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
                                            <td><?= number_format((float) $booking['total'], 2, ',', '.') ?> EUR</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
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
    </script>
</body>

</html>
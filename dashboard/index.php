<?php
session_start();

// Login e Senha para o Dashboard
$USERNAME = 'admin';
$PASSWORD = 'njimko-2010';

// Verifica se o usuário está logado
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// Lógica de Logout
if (isset($_GET['logout']) && $_GET['logout'] === '1') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// CORREÇÃO: O arquivo de log agora está em ../logs/access.log
$log_file = __DIR__ . '/../logs/access.log';
$cache_hits = 'GeoLite2 Local';

if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    file_put_contents($log_file, '');
    header('Location: index.php');
    exit;
}

if (!file_exists($log_file)) {
    echo "<h2>Sem registros ainda.</h2>";
    exit;
}

$lines = file($log_file);
$lines = array_reverse($lines);

$total = 0;
$bots = 0;
$humanos_br = 0;
$humanos_forabr = 0;
$por_pais = [];
$por_ip = [];
$por_status = [];
$data_rows = [];

// Novo: contagem por hora
$hora_white = array_fill(0, 24, 0);
$hora_redir = array_fill(0, 24, 0);

foreach ($lines as $line) {
    if (!preg_match('/\[(.*?)\] IP: (.*?) \| Localização: (.*?) \| Status: (.*?) \| UA: (.*)/', $line, $m)) continue;
    [$all, $time, $ip, $country, $status, $ua] = $m;

    $hora = (int)date('H', strtotime($time));
    if (stripos($status, 'WHITE') !== false) $hora_white[$hora]++;
    if (stripos($status, 'REDIRECIONADO') !== false) $hora_redir[$hora]++;

    $total++;
    if (stripos($status, 'BOT') !== false) $bots++;
    elseif (stripos($status, 'HUMANO BR') !== false) $humanos_br++;
    elseif (stripos($status, 'FORA BR') !== false || stripos($status, 'REDIRECIONADO') !== false) $humanos_forabr++;

    if (!isset($por_pais[$country])) $por_pais[$country] = [];
    $por_pais[$country][] = [
        'time' => $time,
        'ip' => $ip,
        'status' => $status,
        'ua' => $ua
    ];

    $por_ip[$ip] = ($por_ip[$ip] ?? 0) + 1;
    $por_status[$status] = ($por_status[$status] ?? 0) + 1;

    $data_rows[] = compact('time', 'ip', 'country', 'status', 'ua');
}

function country_flag($code) {
    return $code ? "https://flagcdn.com/24x18/" . strtolower($code) . ".png" : '';
}

?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Dashboard de Acessos - Cloaker</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
        }
        nav {
            width: 220px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: #222;
            padding: 20px 0;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            z-index: 10;
        }
        nav a, .nav-link {
            color: white;
            padding: 12px 20px;
            width: 100%;
            text-align: left;
            text-decoration: none;
            font-weight: bold;
            display: block;
            transition: background 0.3s;
        }
        nav a:hover, .nav-link:hover {
            background: #333;
        }
        .reset-btn {
            background: #e74c3c;
            color: white;
            border: none;
            cursor: pointer;
            margin-top: auto; /* Empurra para o final */
        }
        .reset-btn:hover {
            background: #c0392b;
        }
        header {
            position: fixed;
            top: 0;
            left: 220px;
            right: 0;
            height: 60px;
            background: #111;
            color: white;
            display: flex;
            align-items: center;
            padding: 0 20px;
            font-size: 20px;
            z-index: 9;
        }
        .container {
            margin-left: 220px;
            margin-top: 60px;
            padding: 20px;
        }
        @media (max-width: 768px) {
            nav {
                position: relative;
                width: 100%;
                height: auto;
                flex-direction: row;
                justify-content: space-around;
            }
            header {
                position: relative;
                left: 0;
                width: 100%;
                margin-left: 0;
            }
            .container {
                margin-left: 0;
                margin-top: 0;
            }
        }
        h2 { margin-top: 30px; }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            font-size: 14px;
        }
        th { background-color: #222; color: white; }
        .BOT { color: red; font-weight: bold; }
        .WHITE { color: orange; font-weight: bold; }
        .REDIRECIONADO { color: green; font-weight: bold; }
        img.flag { vertical-align: middle; margin-right: 6px; }
        .stat-box { display: inline-block; margin: 10px 30px 10px 0; font-size: 16px; }
        .chart-container { width: 100%; height: 300px; margin-top: 30px; }
    </style>
</head>
<body>
<nav>
	    <div style="padding: 20px; color: white; font-size: 24px; font-weight: bold; border-bottom: 1px solid #333; margin-bottom: 10px;">CLOAKER</div>
	    <a href="#resumo">🔎 Resumo</a>
	    <a href="#graficos">📈 Gráficos</a>
	    <a href="#tabela">📋 Logs</a>
	    <a href="index.php">🔄 Atualizar</a>
	    <a href="?logout=1" class="nav-link" style="margin-top: 20px;">🚪 Sair</a>
	    <a class="reset-btn nav-link" href="?reset=1" onclick="return confirm('ATENÇÃO: Isso apagará todos os logs. Deseja continuar?')">🗑️ Resetar Logs</a>
	</nav>

<header>📊 Dashboard de Acessos</header>

<div class="container">
	    <section id="resumo">
	        <h2 style="margin-top: 0;">🔎 Resumo</h2>
	        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
	            <div style="font-size: 16px; margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
	                Geolocalização: <strong><?= $cache_hits ?></strong>
	            </div>
	            <div style="display:flex; flex-wrap:wrap; gap: 20px;">
	                <div class="stat-card" style="flex:1; min-width: 150px; background:#f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
	                    <h3 style="margin: 0; color: #333;">Total</h3>
	                    <p style="font-size: 24px; font-weight: bold; color: #007bff; margin: 5px 0 0;">
	                        <?= $total ?>
	                    </p>
	                </div>
	                <div class="stat-card" style="flex:1; min-width: 150px; background:#f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
	                    <h3 style="margin: 0; color: #333;">Bots (Bloqueados)</h3>
	                    <p style="font-size: 24px; font-weight: bold; color: #dc3545; margin: 5px 0 0;">
	                        <?= $bots ?>
	                    </p>
	                </div>
	                <div class="stat-card" style="flex:1; min-width: 150px; background:#f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
	                    <h3 style="margin: 0; color: #333;">Humanos BR (White)</h3>
	                    <p style="font-size: 24px; font-weight: bold; color: #ffc107; margin: 5px 0 0;">
	                        <?= $humanos_br ?>
	                    </p>
	                </div>
	                <div class="stat-card" style="flex:1; min-width: 150px; background:#f8f9fa; padding: 15px; border-radius: 6px; text-align: center;">
	                    <h3 style="margin: 0; color: #333;">Humanos Fora BR (Redir.)</h3>
	                    <p style="font-size: 24px; font-weight: bold; color: #28a745; margin: 5px 0 0;">
	                        <?= $humanos_forabr ?>
	                    </p>
	                </div>
	            </div>
	        </div>
	    </section>

    <section style="margin-top: 50px;">
        <h2>📈 Requisições por Hora</h2>
        <div class="chart-container">
            <canvas id="graficoLinha"></canvas>
        </div>
    </section>

	    <section id="graficos" style="display: flex; flex-wrap: wrap; gap: 20px; margin-top: 40px;">
	        <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
	            <h2>🚫 Bloqueados vs. Permitidos</h2>
	            <div class="chart-container" style="height: 300px;">
	                <canvas id="graficoPizza"></canvas>
	            </div>
	        </div>
	        <div style="flex: 1; min-width: 300px; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
	            <h2>🌍 Acessos por País</h2>
	            <div class="chart-container" style="height: 300px;">
	                <canvas id="mapaPaises"></canvas>
	            </div>
	        </div>
	    </section>

	    <section id="tabela" style="margin-top: 40px;">
	        <h2>📋 Logs de Acesso</h2>
	        <div style="background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); overflow-x: auto;">
	            <table>
            <thead>
                <tr>
                    <th>Data/Hora</th>
                    <th>IP</th>
                    <th>Localização</th>
                    <th>Status</th>
                    <th>User-Agent</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($data_rows as $row): 
                $flag = country_flag($row['country']);
                $status_class = strpos($row['status'], 'BOT') !== false ? 'BOT' :
                                (strpos($row['status'], 'REDIRECIONADO') !== false ? 'REDIRECIONADO' : 'WHITE');
            ?>
                <tr>
                    <td><?= htmlspecialchars($row['time']) ?></td>
                    <td><?= htmlspecialchars($row['ip']) ?></td>
                    <td><img class="flag" src="<?= $flag ?>"> <?= htmlspecialchars($row['country']) ?></td>
                    <td class="<?= $status_class ?>"><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= htmlspecialchars($row['ua']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div>

<script>
    const paises = <?= json_encode(array_keys($por_pais)) ?>;
    const paisesQtd = <?= json_encode(array_map("count", $por_pais)) ?>;
    const whiteData = <?= json_encode(array_values($hora_white)) ?>;
    const redirData = <?= json_encode(array_values($hora_redir)) ?>;
    const labels = Array.from({length: 24}, (_, i) => `${i.toString().padStart(2, '0')}h`);

    new Chart(document.getElementById('graficoLinha'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'WHITE (vermelho)',
                    data: whiteData,
                    borderColor: '#e74c3c',
                    fill: false
                },
                {
                    label: 'REDIRECIONADO (verde)',
                    data: redirData,
                    borderColor: '#2ecc71',
                    fill: false
                }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } },
            scales: { y: { beginAtZero: true } }
        }
    });

	    new Chart(document.getElementById('mapaPaises'), {
	        type: 'bar',
	        data: {
	            labels: paises,
	            datasets: [{
	                label: 'Hits por país',
	                data: paisesQtd,
	                backgroundColor: '#3498db'
	            }]
	        },
	        options: {
	            responsive: true,
	            indexAxis: 'y',
	            plugins: { legend: { display: false } },
	            scales: { x: { beginAtZero: true } }
	        }
	    });
	
	    new Chart(document.getElementById('graficoPizza'), {
	        type: 'pie',
	        data: {
	            labels: ['Bots (Bloqueados)', 'Humanos BR (White)', 'Humanos Fora BR (Redir.)'],
	            datasets: [{
	                data: [<?= $bots ?>, <?= $humanos_br ?>, <?= $humanos_forabr ?>],
	                backgroundColor: ['#dc3545', '#ffc107', '#28a745']
	            }]
	        },
	        options: {
	            responsive: true,
	            plugins: {
	                legend: { position: 'bottom' },
	                title: { display: false }
	            }
	        }
	    });
</script>
</body>
</html>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor del Sistema de Licencias</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.2/dist/flatly/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/custom.css">
    <style>
        .status-healthy { color: #28a745; }
        .status-warning { color: #ffc107; }
        .status-unhealthy { color: #dc3545; }
        .metric-card { transition: all 0.3s ease; }
        .metric-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .refresh-btn { position: fixed; bottom: 20px; right: 20px; z-index: 1000; }
        .alert-item { border-left: 4px solid #dc3545; }
        .alert-item.warning { border-left-color: #ffc107; }
        .auto-refresh { background: linear-gradient(45deg, #007bff, #0056b3); }
    </style>
</head>
<body class="bg-gradient">
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center">
                    <h1><i class="fas fa-heartbeat me-2"></i>Monitor del Sistema de Licencias</h1>
                    <div>
                        <button class="btn btn-primary me-2" onclick="toggleAutoRefresh()">
                            <i class="fas fa-sync" id="refreshIcon"></i>
                            <span id="refreshText">Auto-refresh OFF</span>
                        </button>
                        <button class="btn btn-success" onclick="refreshData()">
                            <i class="fas fa-refresh"></i> Actualizar
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estado General -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <i class="fas fa-server fa-2x mb-2" id="systemStatusIcon"></i>
                        <h5>Estado del Sistema</h5>
                        <h3 id="systemStatus" class="mb-0">Cargando...</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <i class="fas fa-hdd fa-2x mb-2 text-info"></i>
                        <h5>Uso de Disco</h5>
                        <h3 id="diskUsage" class="mb-0 text-info">-</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <i class="fas fa-memory fa-2x mb-2 text-warning"></i>
                        <h5>Uso de Memoria</h5>
                        <h3 id="memoryUsage" class="mb-0 text-warning">-</h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card metric-card">
                    <div class="card-body text-center">
                        <i class="fas fa-database fa-2x mb-2 text-success"></i>
                        <h5>Base de Datos</h5>
                        <h3 id="dbStatus" class="mb-0 text-success">-</h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Rate Limiting -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-shield-alt me-2"></i>Rate Limiting</h5>
                    </div>
                    <div class="card-body">
                        <div id="rateLimitInfo">Cargando información de rate limiting...</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line me-2"></i>Estadísticas de API</h5>
                    </div>
                    <div class="card-body">
                        <div id="apiStats">Cargando estadísticas...</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alertas -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-exclamation-triangle me-2"></i>Alertas Recientes</h5>
                    </div>
                    <div class="card-body">
                        <div id="alertsList">Cargando alertas...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let autoRefreshEnabled = false;
        let refreshInterval;

        function refreshData() {
            fetchSystemHealth();
            fetchAlerts();
            fetchRateLimitStatus();
        }

        function fetchSystemHealth() {
            fetch("api_admin_improved.php?action=monitor")
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateSystemStatus(data.health);
                        updateRateLimitInfo(data.rate_limits);
                    }
                })
                .catch(error => {
                    console.error("Error fetching system health:", error);
                    document.getElementById("systemStatus").textContent = "Error";
                    document.getElementById("systemStatusIcon").className = "fas fa-exclamation-triangle fa-2x mb-2 text-danger";
                });
        }

        function fetchAlerts() {
            fetch("api_admin_improved.php?action=alerts&hours=24")
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateAlertsList(data.alerts);
                    }
                })
                .catch(error => console.error("Error fetching alerts:", error));
        }

        function fetchRateLimitStatus() {
            fetch("api_admin_improved.php?action=system_stats")
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        updateSystemStats(data.stats);
                    }
                })
                .catch(error => console.error("Error fetching system stats:", error));
        }

        function updateSystemStatus(health) {
            const statusElement = document.getElementById("systemStatus");
            const iconElement = document.getElementById("systemStatusIcon");
            
            statusElement.textContent = health.status.toUpperCase();
            statusElement.className = `mb-0 status-${health.status}`;
            
            let iconClass = "fas fa-server fa-2x mb-2 ";
            switch(health.status) {
                case "healthy":
                    iconClass += "status-healthy";
                    break;
                case "warning":
                    iconClass += "status-warning";
                    break;
                default:
                    iconClass += "status-unhealthy";
            }
            iconElement.className = iconClass;
        }

        function updateSystemStats(stats) {
            document.getElementById("diskUsage").textContent = stats.disk_usage + "%";
            document.getElementById("memoryUsage").textContent = stats.memory_usage + "%";
            document.getElementById("dbStatus").textContent = "Activa";
        }

        function updateRateLimitInfo(rateLimits) {
            const container = document.getElementById("rateLimitInfo");
            const blockedCount = Object.keys(rateLimits.blocked_ips || {}).length;
            
            container.innerHTML = `
                <p><strong>Requests restantes:</strong> ${rateLimits.remaining}</p>
                <p><strong>IPs bloqueadas:</strong> ${blockedCount}</p>
            `;
        }

        function updateAlertsList(alerts) {
            const container = document.getElementById("alertsList");
            
            if (alerts.length === 0) {
                container.innerHTML = "<p class=\"text-muted\">No hay alertas recientes.</p>";
                return;
            }
            
            let html = "";
            alerts.forEach(alert => {
                const alertData = alert.alert;
                const severity = alertData.severity || "info";
                const icon = severity === "critical" ? "fas fa-exclamation-circle" : "fas fa-exclamation-triangle";
                
                html += `
                    <div class="alert-item ${severity} p-3 mb-2 bg-white rounded border">
                        <div class="d-flex align-items-center">
                            <i class="${icon} me-2"></i>
                            <div class="flex-grow-1">
                                <strong>${alertData.type}</strong>: ${alertData.message}
                            </div>
                            <small class="text-muted">${alert.timestamp}</small>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function toggleAutoRefresh() {
            autoRefreshEnabled = !autoRefreshEnabled;
            const button = document.querySelector(".btn-primary");
            const icon = document.getElementById("refreshIcon");
            const text = document.getElementById("refreshText");
            
            if (autoRefreshEnabled) {
                refreshInterval = setInterval(refreshData, 30000); // 30 segundos
                button.className = "btn auto-refresh me-2";
                icon.className = "fas fa-sync fa-spin";
                text.textContent = "Auto-refresh ON";
            } else {
                clearInterval(refreshInterval);
                button.className = "btn btn-primary me-2";
                icon.className = "fas fa-sync";
                text.textContent = "Auto-refresh OFF";
            }
        }

        // Cargar datos iniciales
        refreshData();
        
        // Actualizar cada 60 segundos si no hay auto-refresh
        setInterval(() => {
            if (!autoRefreshEnabled) {
                refreshData();
            }
        }, 60000);
    </script>
</body>
</html>
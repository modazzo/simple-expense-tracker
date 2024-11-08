<?php
session_start();

// Laden der Konfigurationsdatei
$config = json_decode(file_get_contents('configurations.json'), true);
if (!$config) {
    die("Fehler beim Laden der Konfigurationsdatei.");
}

// Setze die Zeitzone aus der Konfiguration
date_default_timezone_set($config['timezone']);
$current_date = date('Y-m-d'); // Hol das aktuelle Datum im Format YYYY-MM-DD

// Funktion für das Logging
function logline($text, $value = "", $lineNumber = 0) {
    global $config;
    $logFile = $config['log_file'];
    $logLine = "[" . str_pad($lineNumber, 4, "0", STR_PAD_LEFT) . "] " . str_pad($text, 50) . ": " . print_r($value, true) . "\n";
    file_put_contents($logFile, $logLine, FILE_APPEND);
}

// Erweiterte logArray-Funktion zur Handhabung verschachtelter Arrays und Objekte
function logArray($label, $data, $lineNumber = 0) {
    // Überprüfen, ob die Daten ein Array oder Objekt sind
    if (is_array($data) || is_object($data)) {
        foreach ($data as $key => $value) {
            $currentLabel = $label . " -> " . $key;

            // Handhabung verschachtelter Arrays/Objekte rekursiv
            if (is_array($value) || is_object($value)) {
                logline($currentLabel, "[Array/Object]", $lineNumber);
                logArray($currentLabel, $value, $lineNumber);
            } else {
                logline($currentLabel, $value, $lineNumber);
            }
        }
    } else {
        // Loggen, wenn die Daten weder ein Array noch ein Objekt sind
        logline($label, $data, $lineNumber);
    }
}

// Funktion zur Formatierung des Betrags unter Verwendung der Konfiguration
function formatCurrency($amount) {
    global $config;
    return number_format(
        $amount,
        $config['currency_format']['decimals'],
        $config['currency_format']['decimal_separator'],
        $config['currency_format']['thousands_separator']
    ) . ' ' . $config['currency'];
}

// Laden der Benutzer aus user.json
$userData = json_decode(file_get_contents('user.json'), true);
if (!$userData) {
    die("Fehler beim Laden der Benutzerdaten.");
}
logline("Benutzerdaten geladen", $userData, __LINE__);

// Überprüfen, ob der Benutzer bereits eingeloggt ist
if (!isset($_SESSION['loggedin'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $isValid = false;
        foreach ($userData as $user) {
            if ($_POST['username'] === $user['user'] && $_POST['password'] === $user['pw']) {
                $_SESSION['loggedin'] = true;
                $_SESSION['username'] = $user['user'];
                $_SESSION['link'] = $user['link'];
                $isValid = true;
                logline("Benutzer authentifiziert", $user['user'], __LINE__);
                break;
            }
        }
        if (!$isValid) {
            $error = "Ungültiger Benutzername oder Passwort!";
            logline("Authentifizierung fehlgeschlagen", $error, __LINE__);
        }
    }
}

// Wenn nicht eingeloggt, das Login-Formular anzeigen
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Einnahmen- und Ausgaben-Rechnung Vers. 2024</title>
        <!-- Bootstrap 5 CDN -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome CDN -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
        <!-- Meta viewport tag für mobile Geräte -->
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            /* Hover-Effekt für die gesamte Tabelle */
            .table-hover tbody tr:hover {
                background-color: red !important;
            }

            .details-table.table-hover tbody tr:hover {
                background-color: red !important;
            }

            /* Hintergrundfarbe für das Eingabeformular */
            .form-section {
                background-color: #D3D3D3 !important; /* Grau */
                border-radius: 8px;
                padding: 15px;
            }
        </style>
    </head>
    <body class="container my-5">
        <h2 class="mb-4">Bitte einloggen</h2>
        <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form action="" method="post" class="row g-3">
            <div class="col-12">
                <label for="username" class="form-label">Benutzername</label>
                <input type="text" name="username" id="username" class="form-control" required>
            </div>
            <div class="col-12">
                <label for="password" class="form-label">Passwort</label>
                <input type="password" name="password" id="password" class="form-control" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary w-100">Login</button>
            </div>
        </form>
    </body>
    </html>
    <?php
    exit; // Stoppe das Skript hier, wenn nicht eingeloggt
}

// Rest des Skripts (Einnahmen- und Ausgaben-Code) beginnt hier

// JSON-Datei zum Speichern der Transaktionen
$file = 'data.json';

// Hilfsfunktion zum Laden der Daten
function loadData() {
    global $file;
    return json_decode(file_get_contents($file), true) ?: ['users' => [], 'transactions' => []];
}

// Hilfsfunktion zum Speichern der Daten
function saveData($data) {
    global $file;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}

// Initialisieren der Daten
$data = loadData();

logArray("Daten geladen", $data, __LINE__);

// Eintrag hinzufügen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
    $type = $_POST['type'];
    $description = $_POST['description'];
    $amount = (float)$_POST['amount'];
    $date = $_POST['date'];
    $user_id = $_SESSION['username']; // Setze die user_id auf den eingeloggenen Benutzernamen

    // Beleg hochladen
    $receipt_path = null;
    if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        global $config;
        $upload_dir = $config['upload_directory'];
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        $receipt_name = time() . '_' . basename($_FILES['receipt']['name']);
        $receipt_path = $upload_dir . $receipt_name;
        move_uploaded_file($_FILES['receipt']['tmp_name'], $receipt_path);
    }

    // Neuen Eintrag erstellen
    $transaction = [
        'id' => time(),
        'type' => $type,
        'description' => $description,
        'amount' => $amount,
        'date' => $date,
        'user_id' => $user_id,
        'receipt' => $receipt_path
    ];
    $data['transactions'][] = $transaction;
    saveData($data);

    // Redirect, um das erneute Absenden der Formulardaten zu verhindern und zum neuen Eintrag zu springen
    header("Location: " . $_SERVER['PHP_SELF'] . "?highlight_id=" . $transaction['id']);
    exit;
}

// Eintrag löschen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = (int)$_POST['delete_id'];
    $data['transactions'] = array_filter($data['transactions'], function ($transaction) use ($delete_id) {
        return $transaction['id'] !== $delete_id;
    });
    saveData($data);

    // Redirect, um das erneute Absenden der Formulardaten zu verhindern
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Eintrag bearbeiten
$editTransaction = null;
if (isset($_GET['edit_id'])) {
    $edit_id = (int)$_GET['edit_id'];
    foreach ($data['transactions'] as $transaction) {
        if ($transaction['id'] === $edit_id) {
            $editTransaction = $transaction;
            break;
        }
    }
}

// Eintrag speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit'])) {
    $edit_id = (int)$_POST['edit_id'];
    foreach ($data['transactions'] as &$transaction) {
        if ($transaction['id'] === $edit_id) {
            $transaction['type'] = $_POST['type'];
            $transaction['description'] = $_POST['description'];
            $transaction['amount'] = (float)$_POST['amount'];
            $transaction['date'] = $_POST['date'];
            break;
        }
    }
    saveData($data);

    // Redirect, um das erneute Absenden der Formulardaten zu verhindern und zum bearbeiteten Eintrag zu springen
    header("Location: " . $_SERVER['PHP_SELF'] . "?highlight_id=" . $edit_id);
    exit;
}

// Gesamtsummen berechnen
$total_income = 0;
$total_expense = 0;
foreach ($data['transactions'] as $transaction) {
    if ($transaction['type'] === 'income') {
        $total_income += $transaction['amount'];
    } else {
        $total_expense += $transaction['amount'];
    }
}
$saldo = $total_income - $total_expense;

// Farbe für den Saldo bestimmen
$saldo_color = $saldo >= 0 ? 'blue' : 'red';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Einnahmen- und Ausgaben-Rechner</title>
    <!-- Bootstrap 5 CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- Meta viewport tag für mobile Geräte -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Stildefinitionen -->
    <style>
        /* Markierung der aktuell ausgewählten Zeile */
        .selected-row, .selected-row td {
            background-color: yellow !important;
        }

        .details-table tbody tr.selected-row td {
            background-color: yellow !important;
        }

        /* Hover-Effekt für die gesamte Tabelle */
        .table-hover tbody tr:hover {
            background-color: #f5f5f5 !important;
        }

        /* Hintergrundfarbe für das Eingabeformular */
        .form-section {
            background-color: #D3D3D3 !important; /* Grau */
            border-radius: 8px;
            padding: 15px;
        }

        /* Tabelle in einem scrollbaren Div */
        .table-responsive {
            max-height: 400px;
            overflow-y: auto;
        }

        /* Fixiere den Tabellenkopf */
        .details-table thead th {
            position: sticky;
            top: 0;
            background-color: white; /* Passe die Farbe bei Bedarf an */
            z-index: 1;
        }

        /* Anpassungen für kleine Bildschirme */
        @media (max-width: 320px) {
            .details-table thead {
                display: none;
            }
            .details-table tbody tr {
                display: flex;
                flex-direction: column;
                margin-bottom: 15px;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 10px;
            }
            .details-table tbody td {
                display: block;
                padding: .25rem 0;
            }
            .details-table tbody td:first-child,
            .details-table tbody td:nth-child(2) {
                display: inline-block;
                width: auto;
                margin-right: 1px;
            }
            .details-table tbody td:nth-child(3),
            .details-table tbody td:nth-child(4),
            .details-table tbody td:nth-child(5),
            .details-table tbody td:nth-child(6),
            .details-table tbody td:nth-child(7) {
                margin-top: 5px;
            }
            .details-table tbody td[data-label]:before {
                content: attr(data-label) ": ";
                font-weight: bold;
            }
        }
    </style>
</head>
<body class="container my-5">
    <p><h2 class="mb-4">Einnahmen- und Ausgaben-Rechnung</h2> (Income and Expense Statement)</p>

    <!-- Modal für die Bearbeitung -->
    <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="" method="post">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editModalLabel">Eintrag bearbeiten</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="edit_id" id="edit_id" value="">
                        <div class="mb-3">
                            <label for="edit-type" class="form-label">Art</label>
                            <select name="type" id="edit-type" class="form-select">
                                <option value="income">Einnahme</option>
                                <option value="expense">Ausgabe</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="edit-description" class="form-label">Beschreibung</label>
                            <input type="text" name="description" id="edit-description" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit-amount" class="form-label">Betrag (<?php echo htmlspecialchars($config['currency'], ENT_QUOTES, 'UTF-8'); ?>)</label>
                            <input type="number" step="0.01" name="amount" id="edit-amount" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit-date" class="form-label">Datum</label>
                            <input type="date" name="date" id="edit-date" class="form-control" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Abbrechen</button>
                        <button type="submit" name="edit" class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Formular für Einträge -->
    <div class="form-section mb-4">
        <form action="" method="post" enctype="multipart/form-data" class="row g-2">
            <div class="col-12 col-md-2">
                <label for="type" class="visually-hidden">Art</label>
                <select name="type" id="type" class="form-select">
                    <option value="income">Einnahme</option>
                    <option value="expense">Ausgabe</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label for="description" class="visually-hidden">Beschreibung</label>
                <input type="text" name="description" id="description" class="form-control" placeholder="Beschreibung" required>
            </div>
            <div class="col-12 col-md-2">
                <label for="amount" class="visually-hidden">Betrag</label>
                <input type="number" step="0.01" name="amount" id="amount" class="form-control" placeholder="Betrag (<?php echo htmlspecialchars($config['currency'], ENT_QUOTES, 'UTF-8'); ?>)" required>
            </div>
            <div class="col-12 col-md-2">
                <label for="date" class="visually-hidden">Datum</label>
                <input type="date" name="date" id="date" class="form-control" value="<?php echo htmlspecialchars($current_date, ENT_QUOTES, 'UTF-8'); ?>" required>
            </div>
            <div class="col-12 col-md-2">
                <label for="receipt" class="visually-hidden">Beleg hochladen</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="fas fa-upload"></i>
                    </span>
                    <input type="file" name="receipt" id="receipt" class="form-control" accept=".pdf,.jpg,.png">
                </div>
            </div>
            <div class="col-12">
                <button type="submit" name="add" class="btn btn-success w-100 mt-2">
                    <i class="fas fa-save"></i> Speichern
                </button>
            </div>
        </form>
    </div>

    <!-- Übersichtstabelle -->
    <div class="d-flex mb-3">
        <!-- Wrapper für den Navigationsblock -->
        <div style="flex: 0 0 auto;">
            <!-- Button-Leiste -->
            <div id="toolbar1" class="btn-group">
                <button class="btn btn-info mb-2" id="invoice_deleterow" style="height: 40px;">Löschen</button>
                <button class="btn btn-primary mb-2" id="invoice_editrow" style="height: 40px;"><i class="fas fa-edit"></i> Bearbeiten</button>
                <button class="btn btn-secondary" id="show_receipt" style="height: 40px;"><i class="fas fa-file-alt"></i> Beleg anzeigen</button>
            </div>
        </div>

        <!-- Wrapper für die Tabelle -->
        <div style="flex-grow: 1; display: flex; justify-content: flex-end;">
            <div class="table-container" style="width: auto;">
                <table class="table table-hover" style="table-layout: auto; width: auto;">
                    <thead>
                        <tr>
                            <th class="text-start">Einnahmen</th>
                            <th class="text-end">Ausgaben</th>
                            <th class="text-end">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-start"><?php echo htmlspecialchars(formatCurrency($total_income), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end"><?php echo htmlspecialchars(formatCurrency($total_expense), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end">
                                <span style="color: <?php echo htmlspecialchars($saldo_color, ENT_QUOTES, 'UTF-8'); ?>;">
                                    <?php echo htmlspecialchars(formatCurrency($saldo), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <style>
        .btn-group .btn {
            height: 40px; /* Einheitliche Höhe für alle Buttons */
        }

        .table-container {
            display: block;
            margin-left: auto; /* Rechtsbündige Ausrichtung */
        }

        .table th, .table td {
            white-space: nowrap; /* Verhindert Zeilenumbrüche in den Zellen */
        }
    </style>

    <!-- Tabelle mit Details der einzelnen Buchungen -->
    <div class="table-responsive">
        <table class="table table-striped table-hover details-table">
            <thead>
                <tr>
                    <th class="text-start" style="width: 100px;">Datum</th>
                    <th class="text-start" style="width: 100px;">Typ</th>
                    <th class="text-start" style="font-size: 1.2em;">Beschreibung</th>
                    <th class="text-end">Betrag</th>
                    <th class="text-end">Beleg</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['transactions'] as $transaction): ?>
                    <tr data-id="<?php echo htmlspecialchars($transaction['id'], ENT_QUOTES, 'UTF-8'); ?>">
                        <td data-label="Datum"><?php echo htmlspecialchars($transaction['date'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Typ"><?php echo htmlspecialchars(($transaction['type'] === 'income' ? 'Einnahme' : 'Ausgabe') ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Beschreibung"><?php echo htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td data-label="Betrag" class="text-end" style="font-weight: bold; color: <?php echo htmlspecialchars($transaction['type'] === 'income' ? 'blue' : 'red', ENT_QUOTES, 'UTF-8'); ?>;">
                            <?php
                            if ($transaction['type'] === 'income') {
                                echo '+ ' . htmlspecialchars(formatCurrency($transaction['amount'] ?? 0), ENT_QUOTES, 'UTF-8');
                            } else {
                                echo '- ' . htmlspecialchars(formatCurrency($transaction['amount'] ?? 0), ENT_QUOTES, 'UTF-8');
                            }
                            ?>
                        </td>
                        <td data-label="Beleg">
                            <?php if (!empty($transaction['receipt'])): ?>
                                <a href="<?php echo htmlspecialchars($transaction['receipt'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                                    <i class="fas fa-file-alt"></i>
                                </a>
                            <?php else: ?>
                                <i class="fas fa-times"></i>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <style>
        .table th, .table td {
            white-space: nowrap; /* Verhindert Zeilenumbrüche in den Zellen */
        }

        /* Schriftgröße für die Beschreibungsspalte */
        .table th:nth-child(3),
        .table td:nth-child(3) {
            font-size: 1.4em;
        }
    </style>

    <!-- Bootstrap 5 JS Bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // Variable außerhalb deklarieren
        let currentRowIndex = -1;
        let transactions = <?php echo json_encode(array_values($data['transactions'])); ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const tableRows = document.querySelectorAll('.details-table tbody tr');

            tableRows.forEach((row, index) => {
                row.addEventListener('click', function() {
                    // Entferne die Auswahl von allen Zeilen
                    tableRows.forEach(r => r.classList.remove('selected-row'));
                    // Markiere die angeklickte Zeile
                    this.classList.add('selected-row');
                    // Aktualisiere den aktuellen Index
                    currentRowIndex = index;
                });
            });

            // Event-Handler für den Edit-Button
            document.getElementById('invoice_editrow').addEventListener('click', function() {
                const rows = document.querySelectorAll('.details-table tbody tr');

                if (currentRowIndex >= 0 && currentRowIndex < rows.length) {
                    const selectedTransaction = transactions[currentRowIndex];
                    // Fülle das Bearbeitungsformular mit den Daten
                    loadEditData(selectedTransaction);
                    // Öffne das Modal
                    const editModal = new bootstrap.Modal(document.getElementById('editModal'));
                    editModal.show();
                } else {
                    alert('Bitte wählen Sie eine Zeile zum Bearbeiten aus.');
                }
            });

            // Event-Handler für den Delete-Button
            document.getElementById('invoice_deleterow').addEventListener('click', function() {
                const rows = document.querySelectorAll('.details-table tbody tr');

                if (currentRowIndex >= 0 && currentRowIndex < rows.length) {
                    if (confirm('Möchten Sie diesen Eintrag wirklich löschen?')) {
                        const deleteId = transactions[currentRowIndex].id;

                        // Sende eine POST-Anfrage zum Löschen des Eintrags
                        fetch('', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: new URLSearchParams({ delete_id: deleteId })
                        })
                        .then(response => response.text())
                        .then(() => location.reload());
                    }
                } else {
                    alert('Bitte wählen Sie eine Zeile zum Löschen aus.');
                }
            });

            // Event-Handler für den 'Beleg anzeigen' Button
            document.getElementById('show_receipt').addEventListener('click', function() {
                const rows = document.querySelectorAll('.details-table tbody tr');

                if (currentRowIndex >= 0 && currentRowIndex < rows.length) {
                    const selectedTransaction = transactions[currentRowIndex];
                    if (selectedTransaction.receipt) {
                        window.open(selectedTransaction.receipt, '_blank');
                    } else {
                        alert('Für diese Transaktion ist kein Beleg verfügbar.');
                    }
                } else {
                    alert('Bitte wählen Sie eine Zeile aus, um den Beleg anzuzeigen.');
                }
            });

            // Tastaturnavigation hinzufügen
            document.addEventListener('keydown', function(e) {
                const rows = document.querySelectorAll('.details-table tbody tr');
                if (rows.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    if (currentRowIndex < rows.length - 1) {
                        currentRowIndex++;
                        highlightRow(rows, currentRowIndex);
                    }
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (currentRowIndex > 0) {
                        currentRowIndex--;
                        highlightRow(rows, currentRowIndex);
                    }
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    if (currentRowIndex >= 0) {
                        // Aktion bei Drücken der Enter-Taste
                        document.getElementById('invoice_editrow').click();
                    }
                }
            });

            // Funktion zum Hervorheben der aktuell ausgewählten Zeile
            function highlightRow(rows, index) {
                rows.forEach(r => r.classList.remove('selected-row'));
                rows[index].classList.add('selected-row');
                rows[index].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            }

            // Funktion zum Laden der Bearbeitungsdaten in das Modal
            function loadEditData(transaction) {
                document.getElementById('edit-type').value = transaction.type;
                document.getElementById('edit-description').value = transaction.description;
                document.getElementById('edit-amount').value = transaction.amount;
                document.getElementById('edit-date').value = transaction.date;
                document.getElementById('edit_id').value = transaction.id;
            }

            // Nach dem Laden der Seite prüfen, ob ein highlight_id vorhanden ist
            const highlightId = getUrlParameter('highlight_id');
            if (highlightId) {
                const rows = document.querySelectorAll('.details-table tbody tr');
                let foundIndex = -1;
                rows.forEach((row, index) => {
                    if (row.dataset.id == highlightId) {
                        foundIndex = index;
                        return false; // Schleife abbrechen
                    }
                });
                if (foundIndex >= 0) {
                    currentRowIndex = foundIndex;
                    highlightRow(rows, currentRowIndex);
                }
            }

            // Funktion zum Abrufen von URL-Parametern
            function getUrlParameter(name) {
                const urlParams = new URLSearchParams(window.location.search);
                return urlParams.get(name);
            }
        });
    </script>
</body>
</html>

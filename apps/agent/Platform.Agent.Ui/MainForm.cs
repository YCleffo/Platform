using System.Drawing;
using System.IO.Pipes;
using System.Text.Json;
using System.Windows.Forms;
using Platform.Agent;
using System.Security.Principal;

namespace Platform.Agent.Ui;

public sealed class MainForm : Form
{
    private readonly Label _serviceStatusLabel;
    private readonly Label _deviceNumberLabel;
    private readonly Label _sessionStatusLabel;
    private readonly Label _studentLabel;
    private readonly Label _syncInfoLabel;
    private readonly StatusStrip _statusStrip;
    private readonly ToolStripStatusLabel _footerStatus;
    private readonly System.Windows.Forms.Timer _pollTimer;
    private readonly Button _enrollButton;
    private readonly Button _endSessionButton;
    private readonly Button _refreshButton;
    private AgentUiStatus? _lastStatus;
    private bool _querying;
    private bool _actionPending;

    public MainForm()
    {
        Text = "Platform Agent — Учебный компьютер";
        Size = new Size(620, 500);
        MinimumSize = new Size(540, 440);
        StartPosition = FormStartPosition.CenterScreen;
        Font = new Font("Segoe UI", 9.5f, FontStyle.Regular);
        BackColor = Color.FromArgb(248, 250, 252);

        // Header Panel
        var header = new Panel
        {
            Dock = DockStyle.Top,
            Height = 70,
            BackColor = Color.FromArgb(37, 99, 235),
            Padding = new Padding(16, 12, 16, 12)
        };
        var title = new Label
        {
            Text = "Platform Classroom Agent",
            Font = new Font("Segoe UI", 14f, FontStyle.Bold),
            ForeColor = Color.White,
            AutoSize = true,
            Location = new Point(16, 10)
        };
        _serviceStatusLabel = new Label
        {
            Text = "Служба: проверка подключения...",
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            ForeColor = Color.FromArgb(219, 234, 254),
            AutoSize = true,
            Location = new Point(18, 38)
        };
        header.Controls.Add(title);
        header.Controls.Add(_serviceStatusLabel);

        // Main Layout Panel
        var layout = new TableLayoutPanel
        {
            Dock = DockStyle.Fill,
            ColumnCount = 1,
            RowCount = 3,
            Padding = new Padding(16),
            BackColor = Color.Transparent
        };
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 110));
        layout.RowStyles.Add(new RowStyle(SizeType.Absolute, 130));
        layout.RowStyles.Add(new RowStyle(SizeType.Percent, 100));

        // Group 1: Device Information
        var deviceGroup = new GroupBox
        {
            Text = "Параметры компьютера",
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = Color.FromArgb(15, 23, 42)
        };
        _deviceNumberLabel = new Label
        {
            Text = "Номер: Загрузка...\nКласс: —\nСтатус агента: Ожидание",
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            Location = new Point(16, 26),
            Size = new Size(540, 70)
        };
        deviceGroup.Controls.Add(_deviceNumberLabel);

        // Group 2: Session Information
        var sessionGroup = new GroupBox
        {
            Text = "Учебная сессия",
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = Color.FromArgb(15, 23, 42)
        };
        _sessionStatusLabel = new Label
        {
            Text = "Состояние: Свободен (нет активной сессии)",
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = Color.FromArgb(22, 101, 52),
            Location = new Point(16, 26),
            AutoSize = true
        };
        _studentLabel = new Label
        {
            Text = "Ученик: —",
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            Location = new Point(16, 54),
            AutoSize = true
        };
        _syncInfoLabel = new Label
        {
            Text = "Документы: состояние ещё не получено",
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            Location = new Point(16, 78),
            AutoSize = true
        };
        sessionGroup.Controls.Add(_sessionStatusLabel);
        sessionGroup.Controls.Add(_studentLabel);
        sessionGroup.Controls.Add(_syncInfoLabel);

        // Group 3: Quick Actions
        var actionsGroup = new GroupBox
        {
            Text = "Действия",
            Dock = DockStyle.Fill,
            Font = new Font("Segoe UI", 9.5f, FontStyle.Bold),
            ForeColor = Color.FromArgb(15, 23, 42)
        };
        var actionsPanel = new FlowLayoutPanel
        {
            Dock = DockStyle.Fill,
            Padding = new Padding(12),
            AutoScroll = true
        };
        _enrollButton = new Button
        {
            Text = "Привязать агент (Код)",
            Size = new Size(180, 36),
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            BackColor = Color.FromArgb(37, 99, 235),
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat
        };
        _enrollButton.Click += OnEnrollClicked;

        _endSessionButton = new Button
        {
            Text = "Завершить сессию",
            Size = new Size(160, 36),
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            BackColor = Color.FromArgb(239, 68, 68),
            ForeColor = Color.White,
            FlatStyle = FlatStyle.Flat,
            Enabled = false
        };
        _endSessionButton.Click += OnEndSessionClicked;

        _refreshButton = new Button
        {
            Text = "Обновить статус",
            Size = new Size(140, 36),
            Font = new Font("Segoe UI", 9f, FontStyle.Regular),
            BackColor = Color.FromArgb(241, 245, 249),
            ForeColor = Color.FromArgb(51, 65, 85),
            FlatStyle = FlatStyle.Flat
        };
        _refreshButton.Click += async (_, _) => await QueryServiceStatusAsync();

        actionsPanel.Controls.Add(_enrollButton);
        actionsPanel.Controls.Add(_endSessionButton);
        actionsPanel.Controls.Add(_refreshButton);
        actionsGroup.Controls.Add(actionsPanel);

        layout.Controls.Add(deviceGroup, 0, 0);
        layout.Controls.Add(sessionGroup, 0, 1);
        layout.Controls.Add(actionsGroup, 0, 2);

        // Footer
        _statusStrip = new StatusStrip();
        _footerStatus = new ToolStripStatusLabel("Готов");
        _statusStrip.Items.Add(_footerStatus);

        Controls.Add(layout);
        Controls.Add(header);
        Controls.Add(_statusStrip);

        // Timer
        _pollTimer = new System.Windows.Forms.Timer { Interval = 3000 };
        _pollTimer.Tick += async (_, _) => await QueryServiceStatusAsync();
        _pollTimer.Start();
    }

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);
        await QueryServiceStatusAsync();
    }

    private static async Task<AgentIpcResponse> SendAsync(string command, string? code = null, string? sessionId = null)
    {
        await using var pipe = new NamedPipeClientStream(".", "platform-agent", PipeDirection.InOut,
            PipeOptions.Asynchronous, TokenImpersonationLevel.Impersonation);
        using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(8));
        await pipe.ConnectAsync(timeout.Token);
        AgentPipeIdentity.VerifyServer(pipe,
            Environment.GetEnvironmentVariable("PLATFORM_AGENT_ALLOW_CONSOLE_IPC") == "1");
        using var writer = new StreamWriter(pipe, leaveOpen: true) { AutoFlush = true };
        using var reader = new StreamReader(pipe, leaveOpen: true);
        var request = new AgentIpcRequest(1, Guid.NewGuid().ToString(), command, code, sessionId);
        await writer.WriteLineAsync(JsonSerializer.Serialize(request, AgentIpcHandler.JsonOptions).AsMemory(), timeout.Token);
        var line = await reader.ReadLineAsync(timeout.Token);
        if (line is null || line.Length > 16384) throw new IOException("Некорректный ответ службы.");
        var response = JsonSerializer.Deserialize<AgentIpcResponse>(line, AgentIpcHandler.JsonOptions)
            ?? throw new IOException("Пустой ответ службы.");
        if (response.Version != 1 || response.RequestId != request.RequestId)
            throw new IOException("Идентификатор ответа службы не совпадает.");
        return response;
    }

    private async Task QueryServiceStatusAsync()
    {
        if (_querying || _actionPending || IsDisposed) return;
        _querying = true;
        try
        {
            var response = await SendAsync("status");
            if (!response.Ok || response.State is null) throw new IOException("Статус недоступен.");
            ApplyStatus(response.State);
            _serviceStatusLabel.Text = response.State.ServerConnected ? "Служба работает · сервер доступен" : "Служба работает · нет связи с сервером";
            _serviceStatusLabel.ForeColor = Color.FromArgb(187, 247, 208);
            _footerStatus.Text = $"Последнее обновление: {DateTime.Now:HH:mm:ss}";
        }
        catch (Exception failure) when (failure is IOException or OperationCanceledException or JsonException
            or UnauthorizedAccessException or System.ComponentModel.Win32Exception)
        {
            _lastStatus = null;
            _endSessionButton.Enabled = false;
            _enrollButton.Enabled = false;
            _serviceStatusLabel.Text = "Служба недоступна";
            _serviceStatusLabel.ForeColor = Color.FromArgb(254, 202, 202);
            _footerStatus.Text = "Нет ответа от службы Platform Agent";
        }
        finally { _querying = false; }
    }

    private void ApplyStatus(AgentUiStatus state)
    {
        _lastStatus = state;
        _deviceNumberLabel.Text = $"Номер ПК: {state.DeviceNumber ?? "—"}\nКласс: {state.ClassroomName ?? "—"}\nРегистрация: {EnrollmentText(state.EnrollmentState)}";
        _studentLabel.Text = $"Ученик: {state.StudentId ?? "—"}";
        _sessionStatusLabel.Text = "Состояние: " + StateText(state.SessionStatus) + (state.TestAdapter ? " (тестовый адаптер)" : "");
        _syncInfoLabel.Text = state.RecoveryPending ? "Документы сохранены локально. Требуется восстановление." :
            state.Progress is { } progress ? $"Документы: {StateText(progress.Stage)} {progress.Completed}/{progress.Total}" :
            state.SessionStatus == "CLOSED" ? "Учебная сессия отсутствует" : "Документы: ожидание подтверждения";
        if (state.LastError is { } error) _syncInfoLabel.Text += $"\nОшибка: {ErrorText(error.Code)}";
        _endSessionButton.Enabled = !_actionPending && state.CanEndSession;
        _enrollButton.Enabled = !_actionPending && state.CanEnroll;
    }
    private static string EnrollmentText(string value) => value switch
    {
        "confirmed" => "подтверждена", "revoked" => "отозвана", _ => "ожидается привязка администратором",
    };
    private static string StateText(string value) => value switch
    {
        "CLOSED" => "Свободен", "AUTHORIZED" or "PREPARING" => "Готовится",
        "WINDOWS_PROFILE_READY" => "Профиль подготовлен", "SYNCING_IN" or "sync_in" => "Загружаются документы",
        "awaiting_windows_logon" => "Ожидается вход Windows", "ACTIVE" => "Ученик работает",
        "ENDING" => "Завершается", "SYNCING_OUT" or "sync_out" => "Сохраняются документы",
        "STORAGE_CONFIRMED" => "Сохранение подтверждено", "CLEANUP" => "Очистка временного профиля",
        "SYNC_PENDING" or "RECOVERY_REQUIRED" or "FAILED" => "Требуется восстановление", _ => "Ожидание",
    };
    private static string ErrorText(string value) => value switch
    {
        "forbidden" => "Недостаточно прав. Привязку выполняет администратор компьютера.",
        "busy" => "Агент занят. Повторите действие через несколько секунд.",
        "session_mismatch" => "Сессия уже изменилась. Обновите состояние.",
        "session_not_active" => "Сессия не готова к завершению.",
        "invalid_code" or "invalid_enrollment" or "enrollment_expired" => "Проверьте одноразовый код регистрации.",
        "enrollment_blocked" => "Регистрация заблокирована текущей сессией или несохранёнными документами.",
        "network_error" => "Нет связи с сервером. Документы остаются на компьютере.",
        "version_conflict" => "Есть другая версия файла. Обратитесь к преподавателю.",
        "integrity_error" => "Проверка документов не пройдена. Требуется восстановление.",
        _ => "Операция не подтверждена. Обновите состояние или обратитесь к преподавателю.",
    };
    private async Task PerformAsync(string command, string? code = null)
    {
        if (_actionPending) return;
        _actionPending = true;
        _endSessionButton.Enabled = _enrollButton.Enabled = false;
        try
        {
            var response = await SendAsync(command, code, _lastStatus?.SessionId);
            if (response.State is { } state) ApplyStatus(state);
            if (!response.Ok) MessageBox.Show(this, ErrorText(response.Error ?? ""), "Действие не выполнено", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            else _footerStatus.Text = command == "enroll" ? "Регистрация подтверждена сервером" : "Запрос завершения принят сервером";
        }
        catch (Exception failure) when (failure is IOException or OperationCanceledException or JsonException
            or UnauthorizedAccessException or System.ComponentModel.Win32Exception)
        {
            MessageBox.Show(this, "Служба не подтвердила операцию. Обновите состояние перед повтором.", "Нет связи", MessageBoxButtons.OK, MessageBoxIcon.Warning);
        }
        finally { _actionPending = false; await QueryServiceStatusAsync(); }
    }
    private async void OnEnrollClicked(object? sender, EventArgs e)
    {
        if (_lastStatus?.CanEnroll != true) return;
        var code = PromptDialog.Show("Привязка компьютера", "Одноразовый код регистрации из панели преподавателя:");
        if (!string.IsNullOrWhiteSpace(code)) await PerformAsync("enroll", code.Trim());
    }
    private async void OnEndSessionClicked(object? sender, EventArgs e)
    {
        if (_lastStatus?.CanEndSession != true) return;
        if (MessageBox.Show(this, "Завершить учебную сессию и сохранить документы на сервере?", "Подтверждение",
            MessageBoxButtons.YesNo, MessageBoxIcon.Question) == DialogResult.Yes) await PerformAsync("end_session");
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _pollTimer.Stop();
            _pollTimer.Dispose();
        }
        base.Dispose(disposing);
    }
}

public static class PromptDialog
{
    public static string Show(string caption, string prompt)
    {
        var form = new Form
        {
            Width = 420,
            Height = 170,
            FormBorderStyle = FormBorderStyle.FixedDialog,
            Text = caption,
            StartPosition = FormStartPosition.CenterParent,
            MaximizeBox = false,
            MinimizeBox = false
        };
        var label = new Label { Left = 20, Top = 16, Text = prompt, AutoSize = true };
        var textBox = new TextBox { Left = 20, Top = 44, Width = 360 };
        var okButton = new Button { Text = "ОК", Left = 220, Width = 75, Top = 80, DialogResult = DialogResult.OK };
        var cancelButton = new Button { Text = "Отмена", Left = 305, Width = 75, Top = 80, DialogResult = DialogResult.Cancel };

        form.Controls.Add(label);
        form.Controls.Add(textBox);
        form.Controls.Add(okButton);
        form.Controls.Add(cancelButton);
        form.AcceptButton = okButton;
        form.CancelButton = cancelButton;

        return form.ShowDialog() == DialogResult.OK ? textBox.Text : string.Empty;
    }
}


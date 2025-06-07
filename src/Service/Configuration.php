<?php declare(strict_types=1);

namespace Romanzaycev\Vibe\Service;

class Configuration
{
    private array $config = [];
    private string $projectName = 'vibeduck';

    public const ALLOWED_GLOBAL_CONFIG_KEYS = [
        'OPENAI_API_BASE_URI',
        'OPENAI_API_KEY',
        'MODEL_NAME',
        'VIBE_DATA_DIR',
        'HISTORY_LIMIT',
    ];

    public function __construct(?string $projectRootPath = null)
    {
        $this->loadDefaults();
        $this->loadGlobalConfig();
        $this->loadProjectConfig($projectRootPath ?? getcwd());
    }

    private function loadDefaults(): void
    {
        $this->config = [
            'OPENAI_API_BASE_URI' => 'https://api.openai.com/v1',
            'OPENAI_API_KEY' => '',
            'MODEL_NAME' => 'o3-mini',
            'VIBE_DATA_DIR' => '.vibeduck',
            'HISTORY_LIMIT' => 15,
            'IGNORE_DIRS' => [
                "/vendor",
                "/node_modules",
                "./.vibeduck",
                "./git",
            ],
        ];
    }

    private function loadGlobalConfig(): void
    {
        $configDir = $this->getUserConfigDir();
        $configFile = $configDir . DIRECTORY_SEPARATOR . 'config.json';

        if (file_exists($configFile) && is_readable($configFile)) {
            $globalConfig = json_decode(file_get_contents($configFile), true);

            if (is_array($globalConfig)) {
                $this->config = array_merge($this->config, $globalConfig);
            }
        } elseif (!is_dir($configDir)) {
            @mkdir($configDir, 0700, true);
        }
    }

    private function loadProjectConfig(string $currentPath): void
    {
        $configFile = $currentPath . DIRECTORY_SEPARATOR . '.vibeduckrc.json';

        if (file_exists($configFile) && is_readable($configFile)) {
            $projectConfig = json_decode(file_get_contents($configFile), true);

            if (is_array($projectConfig)) {
                $this->config = array_merge($this->config, $projectConfig);
            }
        }
    }

    private function getStoragePath(): string
    {
        $dataDir = $this->getDataDir();

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0700, true);
        }

        return $dataDir . DIRECTORY_SEPARATOR;
    }

    private function getUserConfigDir(): string
    {
        if (getenv('XDG_CONFIG_HOME')) {
            return getenv('XDG_CONFIG_HOME') . DIRECTORY_SEPARATOR . $this->projectName;
        }

        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return getenv('APPDATA') . DIRECTORY_SEPARATOR . ucfirst($this->projectName);
        }

        return implode(DIRECTORY_SEPARATOR, [getenv('HOME') , '.config' , $this->projectName]);
    }

    private function getDataDir(): string
    {
        return implode(DIRECTORY_SEPARATOR, [getcwd(), $this->config["VIBE_DATA_DIR"]]);
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function getAll(): array
    {
        return $this->config;
    }

    // Публичный метод для получения пути к глобальному конфигу
    public function getGlobalConfigFilePath(): string
    {
        $configDir = $this->getUserConfigDir();
        return $configDir . DIRECTORY_SEPARATOR . 'config.json';
    }

    /**
     * Writes a specific key-value pair to the global configuration file.
     *
     * @param string $key The configuration key.
     * @param mixed $value The configuration value.
     * @return bool True on success, false on failure.
     * @throws \InvalidArgumentException If the key is not allowed for global config.
     * @throws \RuntimeException If the config directory/file cannot be written.
     */
    public function writeToGlobalConfig(string $key, $value): bool
    {
        if (!in_array($key, self::ALLOWED_GLOBAL_CONFIG_KEYS, true)) {
            throw new \InvalidArgumentException("Configuration key '{$key}' is not allowed for global setting via this command.");
        }

        $configFile = $this->getGlobalConfigFilePath();
        $configDir = dirname($configFile);

        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0700, true) && !is_dir($configDir)) {
                throw new \RuntimeException("Failed to create global configuration directory: {$configDir}");
            }
        }

        $currentGlobalConfig = [];
        if (file_exists($configFile) && is_readable($configFile)) {
            $data = json_decode(file_get_contents($configFile), true);
            if (is_array($data)) {
                $currentGlobalConfig = $data;
            }
        }

        $currentGlobalConfig[$key] = $value;

        // JSON_PRETTY_PRINT для читаемости файла
        $json_data = json_encode($currentGlobalConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json_data === false) {
            // error_log("Failed to encode global config to JSON: " . json_last_error_msg());
            return false;
        }

        if (file_put_contents($configFile, $json_data, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write to global configuration file: {$configFile}");
        }
        // Установка прав доступа, чтобы только пользователь мог читать/писать
        @chmod($configFile, 0600);

        // Важно: после записи в глобальный конфиг, текущий экземпляр Configuration
        // не будет автоматически "знать" об этом изменении, если loadConfig уже был вызван.
        // Для этой CLI команды это не проблема, т.к. она завершится.
        // Но если бы Configuration был долгоживущим сервисом, потребовалась бы перезагрузка.
        // Или мы можем обновить $this->config[$key] = $value; здесь, если в глобальный конфиг записывается ключ, который
        // не переопределяется локальным. Но это усложнит логику приоритетов.
        // Пока оставляем так: команда запишет, следующий запуск `vibeduck` прочитает.

        return true;
    }
}

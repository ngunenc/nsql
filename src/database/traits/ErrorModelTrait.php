<?php

namespace nsql\database\traits;

use Exception;
use nsql\database\Config;
use nsql\database\exceptions\QueryException;
use nsql\database\logging\Logger;
use nsql\database\security\SensitiveDataFilter;
use PDOException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

/**
 * Hata modeli: structured loglama, safe_execute, THROW_ON_ERROR ve QueryException üretimi.
 */
trait ErrorModelTrait
{
    private ?Logger $logger = null;
    private ?LoggerInterface $psr_logger = null;
    private ?bool $throw_on_error = null;
    private ?PDOException $last_pdo_exception = null;
    private ?Throwable $last_exception = null;

    private function log_error(string $message, array $context = [], int $level = Logger::ERROR): void
    {
        if ($this->psr_logger !== null) {
            $this->psr_logger->log(self::psr_level($level), $message, $context);

            return;
        }

        if ($this->logger === null) {
            $this->logger = new Logger(
                $this->log_file,
                null, // Environment-based level
                true // Structured format
            );
        }

        $this->logger->log($level, $message, $context);
    }

    /**
     * Üretim ortamında ayrıntılı hata mesajlarını gizler, sadece genel mesaj döndürür ve hatayı loglar.
     * Geliştirme ortamında ise gerçek hatayı döndürür.
     *
     * @param Exception|Throwable $e
     * @param string $generic_message Kullanıcıya gösterilecek genel mesaj (örn: "Bir hata oluştu.")
     * @return string Kullanıcıya gösterilecek mesaj
     */
    public function handle_exception(Exception|Throwable $e, string $generic_message = 'Bir hata oluştu.'): string
    {
        $context = [
            'exception' => get_class($e),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        if (method_exists($e, 'getTraceAsString')) {
            $context['trace'] = $e->getTraceAsString();
        }

        $this->log_error($e->getMessage(), $context, Logger::ERROR);

        if ($this->debug_mode) {
            return $e->getMessage();
        } else {
            return $generic_message;
        }
    }

    /**
     * Son yakalanan exception'ı döndürür
     *
     * @return \Throwable|null Son exception veya null
     */
    public function get_last_exception(): ?\Throwable
    {
        return $this->last_exception;
    }

    /**
     * Callable'ı çalıştırır; hata olursa loglar, last_error / get_last_exception() günceller.
     *
     * Sözleşme:
     * - Başarılı: callable'ın dönüş değeri.
     * - Debug modu: orijinal exception fırlatılır (PDOException, mesajı genel mesajla birleştirilmiş
     *   RuntimeException olarak).
     * - THROW_ON_ERROR=true: `RuntimeException($generic_message)` fırlatılır; orijinal hata getPrevious()'ta.
     * - THROW_ON_ERROR=false (1.x varsayılanı, kullanımdan kaldırılacak): aynı RuntimeException
     *   fırlatılmaz, **döndürülür**. Sonucu `instanceof \Throwable` ile kontrol edin.
     *
     * @param callable $fn
     * @param string $generic_message Kullanıcıya gösterilebilir genel mesaj
     * @return mixed
     * @throws \Throwable Debug modunda veya THROW_ON_ERROR=true iken
     */
    public function safe_execute(callable $fn, string $generic_message = 'Bir hata oluştu.'): mixed
    {
        $this->last_exception = null;

        try {
            return $fn();
        } catch (Throwable $e) {
            $this->last_error = $e->getMessage();
            $this->last_exception = $e;
            $this->log_error(sprintf('%s: %s', get_class($e), $e->getMessage()));

            if ($this->debug_mode) {
                if ($e instanceof PDOException) {
                    throw new \RuntimeException($generic_message . ': ' . $e->getMessage(), 0, $e);
                }

                throw $e;
            }

            $safe = new \RuntimeException($generic_message, 0, $e);
            if ($this->throw_on_error()) {
                throw $safe;
            }

            return $safe;
        }
    }

    /**
     * Sorgu hatalarında exception fırlatılsın mı? Örnek ayarı (set_throw_on_error) > THROW_ON_ERROR.
     */
    public function throw_on_error(): bool
    {
        return $this->throw_on_error ?? (bool) Config::get('throw_on_error', Config::throw_on_error);
    }

    /**
     * Bu örnek için hata modelini ayarlar; null = THROW_ON_ERROR ayarını kullan.
     */
    public function set_throw_on_error(?bool $enabled): static
    {
        $this->throw_on_error = $enabled;

        return $this;
    }

    /**
     * Son sorgu hatasından QueryException üretir (parametreler maskelenir).
     */
    private function make_query_exception(string $sql, array $params): QueryException
    {
        $previous = $this->last_pdo_exception;
        $code = $previous !== null && is_numeric($previous->getCode()) ? (int) $previous->getCode() : 0;

        return new QueryException(
            $this->last_error ?? 'Sorgu çalıştırılamadı',
            $sql,
            SensitiveDataFilter::mask_array($params),
            $code,
            $previous
        );
    }

    /**
     * Son hatayı döndürür
     */
    public function get_last_error(): ?string
    {
        return $this->last_error;
    }

    /**
     * Debug bilgilerini loglar (structured logging ile)
     */
    public function log_debug_info(string $message, mixed $data = null): void
    {
        if ($this->debug_mode) {
            $this->log_error($message, $data !== null ? ['data' => $data] : [], Logger::DEBUG);
        }
    }

    /**
     * Logları PSR-3 logger'a (ör. Monolog) yönlendirir; null = dahili dosya logger'ı.
     */
    public function set_logger(?LoggerInterface $logger): static
    {
        $this->psr_logger = $logger;

        return $this;
    }

    private static function psr_level(int $level): string
    {
        return match (true) {
            $level >= Logger::EMERGENCY => LogLevel::EMERGENCY,
            $level >= Logger::ALERT => LogLevel::ALERT,
            $level >= Logger::CRITICAL => LogLevel::CRITICAL,
            $level >= Logger::ERROR => LogLevel::ERROR,
            $level >= Logger::WARNING => LogLevel::WARNING,
            $level >= Logger::NOTICE => LogLevel::NOTICE,
            $level >= Logger::INFO => LogLevel::INFO,
            default => LogLevel::DEBUG,
        };
    }
}

# ADR-0001: `Nsql` trait'lerinin ayrı sınıflara bölünmesi (yol haritası)

- **Durum:** Öneri (uygulanmadı). Hedef sürüm: 3.0.
- **Tarih:** 2026-11-10
- **Kapsam:** `src/database/Nsql.php` ve `src/database/traits/`

## Bağlam

`Nsql` cephe sınıfı 18 trait'ten oluşur. Trait'ler dosyaları küçük tutar ama gerçek bir sınır koymaz: hepsi aynı nesnenin `private` alanlarını paylaşır. Bir trait'i tek başına test etmek için anonim sınıfa takmak ve bağımlı olduğu üyeleri elle sağlamak gerekir (`tests/Unit/QueryCacheTableExtractionTest.php` bunu yapar).

2.7.0 denetimi cache, read/write ayrımı ve streaming'in ayrı sınıflara taşınmasını önerdi. Bu geniş ve kırıcı olabilecek bir değişikliktir (alt sınıflar `protected` üyelere erişebilir, `Nsql` `final` değildir); 2.x içinde yapılmaz.

## Bugünkü bağımlılıklar

Trait'in kendi tanımlamadığı ama `$this` üzerinden kullandığı üyeler (2.7.x kaynağından çıkarıldı):

| Trait | Kendi alanı | Başka yerde tanımlı alanlar | Başka yerde tanımlı metotlar |
|-------|-------------|-----------------------------|------------------------------|
| `CacheTrait` | 20 | `dsn`, `user` (Nsql) | `log_error`, `setting`, `preload_query` |
| `StatementCacheTrait` | 8 | yok | `setting`, `memory_thresholds` |
| `ReadWriteSplitTrait` | 7 | `connection_args`, `dsn_params`, `options`, `debug_mode`, `last_query`, `last_params`, `last_error` (Nsql); `last_pdo_exception`, `psr_logger` (ErrorModelTrait); `query_listeners` (QueryEventsTrait); `bypass_statement_cache` (QueryExecutionTrait) | `execute_query`, `execute_query_uncached`, `get_transaction_level`, `is_connection_lost_error`, `log_error`, `setting`, `throw_on_error` |
| `StreamingTrait` | 1 | `pdo` (ConnectionTrait); `debug_mode`, `last_error` (Nsql) | 22 metot: yürütme (`execute_query`, `bind_parameters`, `prepare_query_context`, `validate_param_types`), bağlantı (`ensure_connection`, `touch_connection`, `validate_pdo_connection`), replica (`reader`, `should_use_reader`, `sync_reader`), hata/olay (`handle_execution_error`, `make_query_exception`, `dispatch_query_event`, `throw_on_error`, `log_error`) ve diğerleri |

Okuma:

- `StatementCacheTrait` ve `CacheTrait` neredeyse kendi kendine yeter: durumlarının tamamı kendilerinde, dışarıdan yalnızca ayar okuma ve loglama isterler.
- `ReadWriteSplitTrait` replica örneğine primary'nin durumunu kopyalar (`sync_reader`); en çok özel alana o dokunur.
- `StreamingTrait` durum taşımaz ama yürütme hattının neredeyse tamamını çağırır; ayrı sınıf olması için önce yürütme hattının arayüzü gerekir.

## Karar (öneri)

Sırayla, her adım kendi başına yayımlanabilir olacak şekilde:

1. **`StatementCache` sınıfı** (en düşük risk). Bağımlılık: ayar okuyucu ve bellek eşikleri. `Nsql` örneği sınıfı alan olarak tutar; public metotlar (`clear_statement_cache()` vb.) ince sarmalayıcı olarak kalır.
2. **`QueryCache` sınıfı.** Bağımlılık: `QueryCacheStore`, ayar okuyucu, logger ve havuz kimliği için `dsn` + `user` (constructor'da verilir). Tablo çıkarımı (`extract_tables_from_query`) durumsuz bir yardımcıya ayrılır.
3. **Yürütme arayüzü** (`QueryExecutor`: prepare / bind / execute / olay / hata). Streaming ve read/write ayrımı buna bağlanır; `$this->pdo` ve `last_*` alanlarına doğrudan erişim kalkar.
4. **`ReadWriteRouter` ve `Streamer`**, 3. adımdaki arayüz üzerinden.

Her adımda: public API değişmez, `tests/Unit/NsqlStructureTest.php` sınırları korunur, davranış testleri (portable + integration) değişmeden geçer.

## Sonuçlar

- Kazanç: bileşenler `Nsql` olmadan test edilir; özel alanlara çapraz erişim derleyici/PHPStan tarafından engellenir.
- Maliyet: `Nsql`'i genişletip `protected` trait üyelerini kullanan kod kırılabilir. Bu yüzden 3.0'a bırakıldı; 2.x'te taşınacak `protected` üyeler önce `@deprecated` işaretlenir (2.8.0'da `ErrorHandlingTrait` yardımcıları için yapıldı).
- 2.x'te yapılmayacak: trait'lerin birleştirilmesi, yeniden adlandırılması veya alan görünürlüklerinin değiştirilmesi.

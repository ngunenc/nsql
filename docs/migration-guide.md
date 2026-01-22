# Migration Guide

Bu kılavuz, nsql kütüphanesini farklı versiyonlar arasında geçiş yaparken dikkat edilmesi gereken değişiklikleri ve adımları içerir.

## 📑 İçindekiler

- [Genel Bakış](#-genel-bakış)
- [v1.3 → v1.4 Geçişi](#-v13--v14-geçişi)
- [Breaking Changes](#-breaking-changes)
- [Yeni Özellikler](#-yeni-özellikler)
- [Deprecated Özellikler](#-deprecated-özellikler)
- [Adım Adım Geçiş](#-adım-adım-geçiş)
- [Sorun Giderme](#-sorun-giderme)

## 🎯 Genel Bakış

nsql kütüphanesi sürümler arası geçişlerde geriye dönük uyumluluğu korumaya çalışır, ancak bazı durumlarda breaking changes gerekebilir. Bu kılavuz, geçiş sürecini kolaylaştırmak için hazırlanmıştır.

## 🔄 v1.3 → v1.4 Geçişi

### Breaking Changes

#### 1. `insert()` Metodu Return Type Değişikliği

**Önceki Versiyon:**
```php
$result = $db->insert("INSERT INTO users (name) VALUES (?)", ['John']);
// $result: bool
```

**Yeni Versiyon:**
```php
$id = $db->insert("INSERT INTO users (name) VALUES (?)", ['John']);
// $id: int|false (son insert ID)
```

**Geçiş:**
```php
// Eski kod
if ($db->insert($sql, $params)) {
    $id = $db->insert_id();
}

// Yeni kod
$id = $db->insert($sql, $params);
if ($id !== false) {
    // Başarılı
}
```

#### 2. Transaction Metodları

**Önceki Versiyon:**
```php
$db->beginTransaction();
$db->commitTransaction();
$db->rollbackTransaction();
```

**Yeni Versiyon:**
```php
$db->begin();  // veya begin_transaction()
$db->commit(); // veya commit_transaction()
$db->rollback(); // veya rollback_transaction()
```

**Not:** Eski metodlar hala çalışır (alias olarak), ancak yeni kod için `begin()`, `commit()`, `rollback()` kullanılması önerilir.

#### 3. Error Handling

**Önceki Versiyon:**
```php
$result = $db->query($sql);
if ($result === false) {
    $error = $db->get_last_error();
}
```

**Yeni Versiyon:**
```php
try {
    $result = $db->query($sql);
} catch (QueryException $e) {
    // Exception ile hata yönetimi
}
```

### Yeni Özellikler

#### 1. Batch İşlemler

```php
// Toplu ekleme
$count = $db->batch_insert('users', [
    ['name' => 'John', 'email' => 'john@example.com'],
    ['name' => 'Jane', 'email' => 'jane@example.com'],
]);

// Toplu güncelleme
$count = $db->batch_update('users', [
    ['id' => 1, 'name' => 'John Doe'],
    ['id' => 2, 'name' => 'Jane Doe'],
]);
```

#### 2. Generator Desteği

```php
// Bellek dostu veri işleme
foreach ($db->get_yield("SELECT * FROM users") as $user) {
    process_user($user);
}

// Chunked fetch
foreach ($db->get_chunk("SELECT * FROM large_table", [], 1000) as $chunk) {
    process_chunk($chunk);
}
```

#### 3. Query Cache İyileştirmeleri

```php
// Cache preload
$db->preload_query("SELECT * FROM users WHERE active = ?", [1]);

// Cache warm-up
$db->warm_cache(true);
```

#### 4. Structured Logging

```php
// Otomatik structured logging
$db->log_debug_info("User created", ['user_id' => $id]);
```

## ⚠️ Deprecated Özellikler

Aşağıdaki özellikler gelecek versiyonlarda kaldırılabilir:

- `beginTransaction()`, `commitTransaction()`, `rollbackTransaction()` → `begin()`, `commit()`, `rollback()` kullanın

## 📋 Adım Adım Geçiş

### 1. Composer Güncelleme

```bash
composer update nsql/nsql
```

### 2. Test Çalıştırma

```bash
vendor/bin/phpunit
```

### 3. Kod İnceleme

Aşağıdaki pattern'leri arayın ve güncelleyin:

```bash
# insert() kullanımlarını kontrol et
grep -r "->insert(" src/

# Transaction metodlarını kontrol et
grep -r "beginTransaction\|commitTransaction\|rollbackTransaction" src/
```

### 4. Adım Adım Güncelleme

#### Adım 1: insert() Metodlarını Güncelle

```php
// Önce
if ($db->insert($sql, $params)) {
    $id = $db->insert_id();
}

// Sonra
$id = $db->insert($sql, $params);
if ($id !== false) {
    // İşlem başarılı
}
```

#### Adım 2: Transaction Metodlarını Güncelle

```php
// Önce
$db->beginTransaction();
try {
    // ...
    $db->commitTransaction();
} catch (Exception $e) {
    $db->rollbackTransaction();
}

// Sonra
$db->begin();
try {
    // ...
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

#### Adım 3: Error Handling Güncelle

```php
// Önce
$result = $db->query($sql);
if ($result === false) {
    $error = $db->get_last_error();
    // Hata yönetimi
}

// Sonra
try {
    $result = $db->query($sql);
} catch (QueryException $e) {
    // Exception ile hata yönetimi
    error_log($e->getMessage());
}
```

### 5. Yeni Özellikleri Kullan

#### Batch İşlemler

```php
// Eski: Döngü ile ekleme
foreach ($users as $user) {
    $db->insert("INSERT INTO users (name, email) VALUES (?, ?)", [$user['name'], $user['email']]);
}

// Yeni: Batch insert
$db->batch_insert('users', $users);
```

#### Generator Kullanımı

```php
// Eski: Tüm veriyi belleğe yükleme
$users = $db->get_results("SELECT * FROM users");
foreach ($users as $user) {
    process_user($user);
}

// Yeni: Generator ile bellek dostu
foreach ($db->get_yield("SELECT * FROM users") as $user) {
    process_user($user);
}
```

## 🔧 Sorun Giderme

### Problem 1: insert() Return Type Hatası

**Hata:**
```
TypeError: Return value must be of type bool, int returned
```

**Çözüm:**
```php
// insert() artık int|false döndürüyor, bool değil
$id = $db->insert($sql, $params);
if ($id !== false) {
    // Başarılı
}
```

### Problem 2: Transaction Hatası

**Hata:**
```
PDOException: There is already an active transaction
```

**Çözüm:**
```php
// Nested transaction desteği eklendi, ancak doğru kullanın
$db->begin();
try {
    // ...
    $db->commit();
} catch (Exception $e) {
    $db->rollback();
}
```

### Problem 3: Cache Invalidation

**Sorun:** Eski cache verileri görünüyor.

**Çözüm:**
```php
// INSERT/UPDATE/DELETE sonrası cache otomatik temizleniyor
// Manuel temizleme gerekirse:
$db->clear_query_cache();
```

## 📊 Performans İyileştirmeleri

v1.4'te yapılan performans iyileştirmeleri:

1. **Connection Pool Optimizasyonu**: Dinamik pool sizing
2. **Query Cache**: Table-based invalidation
3. **Statement Cache**: Geliştirilmiş cache stratejisi
4. **Memory Management**: Chunked fetch ile bellek optimizasyonu

## 🔐 Güvenlik İyileştirmeleri

1. **SQL Injection Protection**: Geliştirilmiş pattern detection
2. **Input Validation**: Genişletilmiş validation kuralları
3. **Error Handling**: Güvenli hata mesajları

## 📝 Test Etme

Geçiş sonrası testler:

```bash
# Tüm testleri çalıştır
vendor/bin/phpunit

# Belirli test sınıfı
vendor/bin/phpunit tests/nsql_test.php

# Coverage raporu
vendor/bin/phpunit --coverage-html coverage/
```

## 🆘 Yardım

Sorun yaşarsanız:

1. [Troubleshooting Guide](../TROUBLESHOOTING.md) dosyasına bakın
2. [GitHub Issues](https://github.com/your-repo/nsql/issues) üzerinden sorun bildirin
3. [API Reference](api-reference.md) dokümantasyonunu kontrol edin

---

**Son Güncelleme**: 2026-01-22  
**Versiyon**: 1.4.0

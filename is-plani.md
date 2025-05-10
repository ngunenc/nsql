# İş Planı Checklist

## Eksikler
- [x] **Hata Yönetimi**: PDO hataları için loglama sistemi eklendi ancak daha gelişmiş bir hata izleme mekanizması (örneğin, üçüncü taraf bir hata izleme aracı) entegre edilebilir.
- [x] **Kod Dokümantasyonu**: Metotlar için PHPDoc yorumları eksik. Her metodun ne yaptığı, parametrelerin ve dönüş değerlerinin açıklamaları eklenmeli.
- [ ] **Test Edilebilirlik**: Kodda birim testler için bir yapı bulunmuyor. Örneğin, `query`, `insert`, `get_row` gibi metotlar için birim testler yazılabilir.
- [ ] **SQL Injection Koruması**: `interpolateQuery` metodu, parametreleri manuel olarak sorguya yerleştiriyor. Bu, SQL enjeksiyon riskini artırabilir. Bu metot yalnızca hata ayıklama amacıyla kullanılmalı.

## Hatalar
- [ ] **Tip Güvenliği**: PDO'nun `lastInsertId` metodu bir `string` döndürüyor, ancak kodda bu değer `int` olarak atanıyor. Bu dönüşüm doğru olsa da, tip uyumsuzluğu yaratabilir.
- [ ] **Bağlantı Yönetimi**: Uzun süreli bağlantılar için bir "bağlantı kontrolü" mekanizması eksik. Bağlantının hala aktif olup olmadığını kontrol eden bir metot eklenebilir.

## İyileştirmeler
- [x] **Kod Tekrarını Azaltma**: `get_row` ve `get_results` metotlarında kod tekrarı kaldırıldı. Benzer şekilde, `insert`, `update`, ve `delete` metotları için ortak bir yardımcı metot oluşturulabilir.
- [ ] **Performans İyileştirmeleri**: `fetchAll(PDO::FETCH_OBJ)` kullanımı, büyük veri setlerinde bellek tüketimini artırabilir. Büyük veri setleri için bir "iterator" yaklaşımı tercih edilebilir.
- [ ] **PDOStatement Önbellekleme**: `statementCache` kullanımı performans açısından faydalı, ancak önbelleğin boyutunu kontrol eden bir mekanizma yok. Çok fazla sorgu çalıştırıldığında bellek tüketimi artabilir. Önbellek boyutunu sınırlamak için bir LRU (Least Recently Used) algoritması eklenebilir.
- [x] **Debug Modu**: `debug` metodu, hata ayıklama bilgilerini log dosyasına yazacak şekilde düzenlendi. Ancak, üretim ortamında bu metot devre dışı bırakılmalı veya daha güvenli bir şekilde kullanılmalı.

## Notlar
- Kodlamalar yapılırken ana kod yapısı bozulmamalıdır. Bu not her değişiklikte dikkate alınmalıdır.

## Yapılacaklar
- [x] Metotlar için PHPDoc yorumları eklenmesi.
- [ ] Birim testler için bir test framework'ü (örneğin, PHPUnit) entegre edilmesi.
- [ ] `interpolateQuery` metodunun yalnızca hata ayıklama amacıyla kullanılmasını sağlamak için bir kontrol mekanizması eklenmesi.
- [ ] PDO bağlantısının aktif olup olmadığını kontrol eden bir metot eklenmesi.
- [ ] Performans iyileştirmeleri için büyük veri setlerinde iterator kullanımı.
- [ ] `statementCache` için bir LRU algoritması eklenmesi.
- [ ] Debug modunun üretim ortamında devre dışı bırakılması için bir yapılandırma seçeneği eklenmesi.
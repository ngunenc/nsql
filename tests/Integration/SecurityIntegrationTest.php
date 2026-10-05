<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\Nsql;

class SecurityIntegrationTest extends DatabaseTestCase
{
    public function testSecurity()
    {
        // XSS koruması testi
        $input = '<script>alert("xss")</script>';
        $escaped = Nsql::escape_html($input);
        $this->assertNotEquals($input, $escaped);

        // CSRF token testi
        $token = \nsql\security\SessionManager::get_csrf_token();
        $this->assertTrue(\nsql\security\SessionManager::validate_csrf_token($token));

        // Şifreleme testi
        $encryption = new \nsql\security\Encryption();
        $data = 'sensitive_data';
        $encrypted = $encryption->encrypt($data);
        $decrypted = $encryption->decrypt($encrypted);
        $this->assertEquals($data, $decrypted);
    }

    public function testSQLInjectionProtection()
    {
        // SQL injection denemesi
        $maliciousInput = "'; DROP TABLE test_table; --";

        $id = $this->db->insert(
            "INSERT INTO test_table (name) VALUES (:name)",
            ['name' => $maliciousInput]
        );

        $this->assertTrue($id > 0);

        // Tablonun hala var olduğunu kontrol et
        $result = $this->db->get_results("SELECT COUNT(*) as count FROM test_table");
        $this->assertNotEmpty($result);

        // Kaydın güvenli şekilde eklendiğini kontrol et
        $row = $this->db->get_row(
            "SELECT * FROM test_table WHERE id = :id",
            ['id' => $id]
        );
        $this->assertNotNull($row);
        $this->assertEquals($maliciousInput, $row->name);
    }

    public function testXSSProtection()
    {
        $xssInputs = [
            '<script>alert("xss")</script>',
            '<img src=x onerror=alert("xss")>',
            'javascript:alert("xss")',
            '<svg onload=alert("xss")>',
        ];

        foreach ($xssInputs as $input) {
            $escaped = Nsql::escape_html($input);
            $this->assertNotEquals($input, $escaped);
            $this->assertStringNotContainsString('<script>', $escaped);
        }
    }

    public function testCSRFProtection()
    {
        // Token oluştur
        $token1 = Nsql::csrf_token();
        $this->assertNotEmpty($token1);

        // Aynı token'ı doğrula
        $this->assertTrue(Nsql::validate_csrf($token1));

        // Farklı token'ı doğrula (başarısız olmalı)
        $token2 = Nsql::csrf_token();
        $this->assertFalse(Nsql::validate_csrf('invalid_token'));
    }
}

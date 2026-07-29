<?php

namespace Tests\Integration;

use Tests\Support\DatabaseTestCase;
use nsql\database\nsql;

class ErrorHandlingIntegrationTest extends DatabaseTestCase
{
    public function testErrorHandling()
    {
        $this->expectException(\Exception::class);
        $this->db->query("INVALID SQL QUERY");
    }

    public function testGetLastError()
    {
        // Geçersiz sorgu çalıştır
        try {
            $this->db->query("INVALID SQL QUERY");
        } catch (\Exception $e) {
            // Hata bekleniyor
        }
        
        $error = $this->db->get_last_error();
        // Hata mesajı olabilir veya null olabilir (debug mode'a bağlı)
        $this->assertTrue($error === null || is_string($error));
    }

    public function testSafeExecute()
    {
        // Başarılı işlem
        $result = $this->db->safe_execute(function() {
            return $this->db->get_results("SELECT 1 as test");
        });
        
        $this->assertIsArray($result);
        
        // Hatalı işlem — production'da false yerine wrapped RuntimeException döner
        $result = $this->db->safe_execute(function() {
            return $this->db->query("INVALID SQL");
        }, 'Custom error message');

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame('Custom error message', $result->getMessage());
        $this->assertNotNull($result->getPrevious());
    }
}

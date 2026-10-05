<?php

namespace Tests\Unit;

use nsql\database\cache\SafeSerializer;
use PHPUnit\Framework\TestCase;

class SafeSerializerTest extends TestCase
{
    public function test_encode_uses_safe_prefix(): void
    {
        $payload = SafeSerializer::encode(['a' => 1, 'b' => 'x']);
        $this->assertTrue(SafeSerializer::is_safe_payload($payload));
        $this->assertStringStartsWith(SafeSerializer::PREFIX, $payload);
    }

    public function test_roundtrip_array_and_scalars(): void
    {
        $cases = [
            ['id' => 1, 'name' => 'nec'],
            'plain-string',
            42,
            3.14,
            true,
            false,
            null,
            [1, 2, ['nested' => true]],
        ];

        foreach ($cases as $case) {
            $decoded = SafeSerializer::decode(SafeSerializer::encode($case));
            $this->assertSame($case, $decoded);
        }
    }

    public function test_legacy_scalar_unserialize_allowed_without_objects(): void
    {
        $legacy = serialize(['ok' => true, 'n' => 7]);
        $this->assertFalse(SafeSerializer::is_safe_payload($legacy));
        $this->assertSame(['ok' => true, 'n' => 7], SafeSerializer::decode($legacy));
        $this->assertFalse(SafeSerializer::decode(serialize(false)));
    }

    public function test_legacy_object_payload_is_rejected(): void
    {
        $legacy = serialize((object) ['evil' => true]);
        $this->assertNull(SafeSerializer::decode($legacy));
    }

    public function test_object_injection_gadget_is_rejected(): void
    {
        // Simulated poisoned cache: serialized object (no real gadget class required)
        $poison = 'O:8:"stdClass":1:{s:1:"x";s:3:"bad";}';
        $this->assertNull(SafeSerializer::decode($poison));
    }

    public function test_malformed_json_payload_returns_null(): void
    {
        $this->assertNull(SafeSerializer::decode(SafeSerializer::PREFIX . '{not-json'));
    }

    public function test_empty_raw_returns_null(): void
    {
        $this->assertNull(SafeSerializer::decode(''));
    }
}

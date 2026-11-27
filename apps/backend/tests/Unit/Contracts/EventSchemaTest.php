<?php

declare(strict_types=1);

namespace App\Tests\Unit\Contracts;

use Codeception\Test\Unit;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

final class EventSchemaTest extends Unit
{
    public function testVersionedFixturesMatchEnvelopeAndPayloadSchemas(): void
    {
        $directory = $this->contractsDirectory();
        $envelope = json_decode((string) file_get_contents($directory . '/envelope.v1.schema.json'));
        self::assertIsObject($envelope);

        foreach (glob($directory . '/fixtures/*.v1.json') ?: [] as $fixturePath) {
            $event = json_decode((string) file_get_contents($fixturePath));
            self::assertIsObject($event);
            $validator = new Validator();
            $validator->validate($event, $envelope, Constraint::CHECK_MODE_APPLY_DEFAULTS);
            self::assertTrue($validator->isValid(), $fixturePath . ': ' . json_encode($validator->getErrors()));

            $payloadSchema = json_decode((string) file_get_contents($directory . '/' . $event->event_type . '.v1.schema.json'));
            self::assertIsObject($payloadSchema);
            $validator = new Validator();
            $validator->validate($event->payload, $payloadSchema);
            self::assertTrue($validator->isValid(), $fixturePath . ': ' . json_encode($validator->getErrors()));
        }
    }

    public function testUnversionedBreakingPayloadIsRejected(): void
    {
        $directory = $this->contractsDirectory();
        $schema = json_decode((string) file_get_contents($directory . '/control_user.email_verification_requested.v1.schema.json'));
        $fixture = json_decode((string) file_get_contents($directory . '/fixtures/control_user.email_verification_requested.v1.json'));
        self::assertIsObject($schema);
        self::assertIsObject($fixture);
        unset($fixture->payload->verification_id);
        $validator = new Validator();
        $validator->validate($fixture->payload, $schema);
        self::assertFalse($validator->isValid());
    }

    private function contractsDirectory(): string
    {
        $candidates = [
            '/contracts/events',
            dirname(__DIR__, 5) . '/contracts/events',
            dirname(__DIR__, 4) . '/contracts/events',
        ];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        return dirname(__DIR__, 5) . '/contracts/events';
    }
}

<?php
declare(strict_types=1);

use Remita\BigCommerce\BigCommerceClientInterface;
use Remita\BigCommerce\Support\IdempotencyStoreInterface;
use Remita\BigCommerce\Support\LoggerInterface;
use Remita\BigCommerce\Support\PaymentIdentifier;
use Remita\BigCommerce\Support\PaymentStatusMapper;
use Remita\BigCommerce\Webhook\WebhookProcessor;

// ---------------------------------------------------------------------------
// Minimal in-memory stubs for BigCommerceClient and IdempotencyStore
// so no HTTP calls or filesystem writes are made during these tests.
// ---------------------------------------------------------------------------

/**
 * Records calls to getOrder / updateOrder / createOrderMetafield.
 * Implements the interface directly — no need to extend the final concrete class.
 */
class StubBigCommerceClient implements BigCommerceClientInterface
{
    /** @var array<string,mixed> */
    public array $orderData = ['payment_status' => ''];

    /** @var list<array<string,mixed>> */
    public array $updateCalls = [];

    /** @var list<array<string,mixed>> */
    public array $metafieldCalls = [];

    public function getOrder(int $orderId): array
    {
        return $this->orderData;
    }

    public function updateOrder(int $orderId, array $fields): array
    {
        $this->updateCalls[] = ['orderId' => $orderId, 'fields' => $fields];
        return [];
    }

    public function createOrderMetafield(int $orderId, string $key, string $value): array
    {
        $this->metafieldCalls[] = ['orderId' => $orderId, 'key' => $key, 'value' => $value];
        return [];
    }

    public function getOrderMetafields(int $orderId): array
    {
        return [];
    }

    public function getStoreInfo(): array
    {
        return [];
    }
}

/**
 * In-memory idempotency store — does NOT touch the filesystem.
 * Implements IdempotencyStoreInterface directly.
 */
class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string,mixed> */
    private array $store = [];

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->store);
    }

    public function set(string $key, mixed $value): void
    {
        $this->store[$key] = $value;
    }

    public function get(string $key): mixed
    {
        return $this->store[$key] ?? null;
    }
}

/**
 * In-memory logger — does NOT touch the filesystem.
 * Implements LoggerInterface directly.
 */
class InMemoryLogger implements LoggerInterface
{
    /** @var list<array<string,mixed>> */
    public array $records = [];

    public function info(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'INFO', 'message' => $message, 'context' => $context];
    }

    public function warning(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'WARNING', 'message' => $message, 'context' => $context];
    }

    public function error(string $message, array $context = []): void
    {
        $this->records[] = ['level' => 'ERROR', 'message' => $message, 'context' => $context];
    }
}

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

class WebhookProcessorTest extends TestCase
{
    private const SECRET = 'test-webhook-secret';

    public function run(): void
    {
        $this->testSuccessfulWebhookUpdatesOrder();
        $this->testDuplicateWebhookIsSkipped();
        $this->testRegressionGuardPreventsDowngrade();
        $this->testInvalidSignatureThrows();
        $this->testMissingSignatureThrows();
        $this->testMissingTransactionIdThrows();
        $this->testMissingPaymentIdentifierThrows();
        $this->testPendingWebhookSetsPending();
        $this->testFailedWebhookSetsIncomplete();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeProcessor(StubBigCommerceClient $bcClient, InMemoryIdempotencyStore $idempotency): WebhookProcessor
    {
        /** @var callable(string): BigCommerceClientInterface $factory */
        $factory = static fn (string $storeHash): BigCommerceClientInterface => $bcClient;

        return new WebhookProcessor(
            bcClientFactory: $factory,
            identifier:      new PaymentIdentifier(),
            statusMapper:    new PaymentStatusMapper(),
            idempotency:     $idempotency,
            logger:          new InMemoryLogger(),
            webhookSecret:   self::SECRET,
        );
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, self::SECRET);
    }

    /** @return array<string,string> */
    private function headers(string $body): array
    {
        return ['x-remita-signature' => $this->sign($body)];
    }

    private function makePayload(string $status, string $paymentIdentifier, string $transactionId = 'TXN-001'): string
    {
        return json_encode([
            'transactionId'     => $transactionId,
            'paymentIdentifier' => $paymentIdentifier,
            'status'            => $status,
        ]);
    }

    private function generateId(string $storeHash = 'abc123', int $orderId = 1): string
    {
        return (new PaymentIdentifier())->generate($storeHash, $orderId);
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    private function testSuccessfulWebhookUpdatesOrder(): void
    {
        $bcClient   = new StubBigCommerceClient();
        $idempotency = new InMemoryIdempotencyStore();
        $processor  = $this->makeProcessor($bcClient, $idempotency);

        $pid  = $this->generateId('abc123', 42);
        $body = $this->makePayload('success', $pid, 'TXN-100');

        $processor->process($body, $this->headers($body));

        $this->assertSame(1, count($bcClient->updateCalls), 'updateOrder called once');
        $this->assertSame(10, $bcClient->updateCalls[0]['fields']['status_id'], 'status_id=10 for success');
        $this->assertSame('captured', $bcClient->updateCalls[0]['fields']['payment_status'], 'payment_status=captured');

        // Transaction ID stored in metafield.
        $this->assertSame(1, count($bcClient->metafieldCalls), 'metafield written on success');
        $this->assertSame('remita_transaction_id', $bcClient->metafieldCalls[0]['key'], 'metafield key');
        $this->assertSame('TXN-100', $bcClient->metafieldCalls[0]['value'], 'metafield value');

        // Idempotency record persisted.
        $this->assertTrue($idempotency->has('wh:TXN-100:success'), 'idempotency key stored');
    }

    private function testDuplicateWebhookIsSkipped(): void
    {
        $bcClient   = new StubBigCommerceClient();
        $idempotency = new InMemoryIdempotencyStore();
        $processor  = $this->makeProcessor($bcClient, $idempotency);

        $pid  = $this->generateId('abc123', 1);
        $body = $this->makePayload('success', $pid, 'TXN-DUP');

        // First call.
        $processor->process($body, $this->headers($body));
        // Second call — must be skipped.
        $processor->process($body, $this->headers($body));

        // updateOrder should only have been called once.
        $this->assertSame(1, count($bcClient->updateCalls), 'duplicate webhook not re-processed');
    }

    private function testRegressionGuardPreventsDowngrade(): void
    {
        $bcClient           = new StubBigCommerceClient();
        $bcClient->orderData = ['payment_status' => 'captured']; // already success
        $idempotency         = new InMemoryIdempotencyStore();
        $processor           = $this->makeProcessor($bcClient, $idempotency);

        $pid  = $this->generateId('abc123', 5);
        $body = $this->makePayload('failed', $pid, 'TXN-REG');

        $processor->process($body, $this->headers($body));

        // Should have been updated to success (10), not failed (1).
        $this->assertSame(10, $bcClient->updateCalls[0]['fields']['status_id'], 'regression guard: success kept');
    }

    private function testInvalidSignatureThrows(): void
    {
        $processor = $this->makeProcessor(new StubBigCommerceClient(), new InMemoryIdempotencyStore());

        $pid  = $this->generateId();
        $body = $this->makePayload('success', $pid);

        $caught = false;
        try {
            $processor->process($body, ['x-remita-signature' => 'bad-signature']);
        } catch (\RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'invalid signature throws RuntimeException');
    }

    private function testMissingSignatureThrows(): void
    {
        $processor = $this->makeProcessor(new StubBigCommerceClient(), new InMemoryIdempotencyStore());

        $pid  = $this->generateId();
        $body = $this->makePayload('success', $pid);

        $caught = false;
        try {
            $processor->process($body, []);
        } catch (\RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'missing signature throws RuntimeException');
    }

    private function testMissingTransactionIdThrows(): void
    {
        $processor = $this->makeProcessor(new StubBigCommerceClient(), new InMemoryIdempotencyStore());

        $pid  = $this->generateId();
        $body = json_encode(['paymentIdentifier' => $pid, 'status' => 'success']);

        $caught = false;
        try {
            $processor->process($body, $this->headers($body));
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'missing transactionId throws InvalidArgumentException');
    }

    private function testMissingPaymentIdentifierThrows(): void
    {
        $processor = $this->makeProcessor(new StubBigCommerceClient(), new InMemoryIdempotencyStore());

        $body = json_encode(['transactionId' => 'TXN-X', 'status' => 'success']);

        $caught = false;
        try {
            $processor->process($body, $this->headers($body));
        } catch (\InvalidArgumentException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'missing paymentIdentifier throws InvalidArgumentException');
    }

    private function testPendingWebhookSetsPending(): void
    {
        $bcClient   = new StubBigCommerceClient();
        $idempotency = new InMemoryIdempotencyStore();
        $processor  = $this->makeProcessor($bcClient, $idempotency);

        $pid  = $this->generateId('store2', 10);
        $body = $this->makePayload('pending', $pid, 'TXN-PND');

        $processor->process($body, $this->headers($body));

        $this->assertSame(2, $bcClient->updateCalls[0]['fields']['status_id'], 'pending => status_id=2');
        $this->assertSame('pending', $bcClient->updateCalls[0]['fields']['payment_status'], 'payment_status=pending');
        $this->assertSame(0, count($bcClient->metafieldCalls), 'no metafield written for pending');
    }

    private function testFailedWebhookSetsIncomplete(): void
    {
        $bcClient   = new StubBigCommerceClient();
        $idempotency = new InMemoryIdempotencyStore();
        $processor  = $this->makeProcessor($bcClient, $idempotency);

        $pid  = $this->generateId('store3', 20);
        $body = $this->makePayload('error', $pid, 'TXN-FAIL');

        $processor->process($body, $this->headers($body));

        $this->assertSame(1, $bcClient->updateCalls[0]['fields']['status_id'], 'failed => status_id=1');
        $this->assertSame('failed', $bcClient->updateCalls[0]['fields']['payment_status'], 'payment_status=failed');
    }
}

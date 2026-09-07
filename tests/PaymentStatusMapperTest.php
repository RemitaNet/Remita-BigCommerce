<?php
declare(strict_types=1);

use Remita\BigCommerce\Support\PaymentStatusMapper;

class PaymentStatusMapperTest extends TestCase
{
    private PaymentStatusMapper $mapper;

    public function __construct()
    {
        $this->mapper = new PaymentStatusMapper();
    }

    public function run(): void
    {
        $this->testSuccessCode();
        $this->testSuccessState();
        $this->testPendingCodes();
        $this->testPendingState();
        $this->testFailedDefault();
        $this->testWebhookSuccess();
        $this->testWebhookPending();
        $this->testWebhookFailed();
        $this->testResolveGuardsSuccess();
        $this->testResolveGuardsPaidVariants();
        $this->testResolveAllowsPendingToFail();
        $this->testResolveEmptyCurrentAcceptsIncoming();
    }

    // fromApiResponse tests

    private function testSuccessCode(): void
    {
        $result = $this->mapper->fromApiResponse(['status' => '00']);
        $this->assertSame('success', $result, 'status 00 => success');
    }

    private function testSuccessState(): void
    {
        $result = $this->mapper->fromApiResponse(['status' => '99', 'paymentState' => 'APPROVED']);
        $this->assertSame('success', $result, 'paymentState APPROVED => success');
    }

    private function testPendingCodes(): void
    {
        foreach (['01', '02', '03', '04', '09', '45'] as $code) {
            $result = $this->mapper->fromApiResponse(['status' => $code]);
            $this->assertSame('pending', $result, "status {$code} => pending");
        }
    }

    private function testPendingState(): void
    {
        foreach (['PENDING', 'PROCESSING'] as $state) {
            $result = $this->mapper->fromApiResponse(['paymentState' => $state]);
            $this->assertSame('pending', $result, "paymentState {$state} => pending");
        }
    }

    private function testFailedDefault(): void
    {
        $result = $this->mapper->fromApiResponse(['status' => '99', 'paymentState' => 'UNKNOWN']);
        $this->assertSame('failed', $result, 'unknown code and state => failed');
    }

    // fromWebhookPayload tests

    private function testWebhookSuccess(): void
    {
        foreach (['success', 'approved', 'completed'] as $s) {
            $result = $this->mapper->fromWebhookPayload(['status' => $s]);
            $this->assertSame('success', $result, "webhook status {$s} => success");
        }
        // Case-insensitive
        $result = $this->mapper->fromWebhookPayload(['status' => 'SUCCESS']);
        $this->assertSame('success', $result, 'webhook status SUCCESS (upper) => success');
    }

    private function testWebhookPending(): void
    {
        foreach (['pending', 'processing', 'redirect'] as $s) {
            $result = $this->mapper->fromWebhookPayload(['status' => $s]);
            $this->assertSame('pending', $result, "webhook status {$s} => pending");
        }
    }

    private function testWebhookFailed(): void
    {
        $result = $this->mapper->fromWebhookPayload(['status' => 'error']);
        $this->assertSame('failed', $result, 'unknown webhook status => failed');

        $result = $this->mapper->fromWebhookPayload([]);
        $this->assertSame('failed', $result, 'missing status key => failed');
    }

    // resolve tests (regression guard)

    private function testResolveGuardsSuccess(): void
    {
        $result = $this->mapper->resolve('success', 'failed');
        $this->assertSame('success', $result, 'success must not be downgraded to failed');

        $result = $this->mapper->resolve('success', 'pending');
        $this->assertSame('success', $result, 'success must not be downgraded to pending');
    }

    private function testResolveGuardsPaidVariants(): void
    {
        foreach (['paid', 'complete', 'completed', 'captured'] as $terminal) {
            $result = $this->mapper->resolve($terminal, 'failed');
            $this->assertSame('success', $result, "{$terminal} must resolve to success");
        }
    }

    private function testResolveAllowsPendingToFail(): void
    {
        $result = $this->mapper->resolve('pending', 'failed');
        $this->assertSame('failed', $result, 'pending can transition to failed');
    }

    private function testResolveEmptyCurrentAcceptsIncoming(): void
    {
        $result = $this->mapper->resolve('', 'success');
        $this->assertSame('success', $result, 'empty current => use incoming');
    }
}

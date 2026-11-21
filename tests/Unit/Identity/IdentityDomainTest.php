<?php

declare(strict_types=1);

use Modules\Platform\Identity\Domain\Enums\FieldVisibility;
use Modules\Platform\Identity\Domain\Enums\SystemRole;
use Modules\Platform\Identity\Domain\Exceptions\InvalidPermissionKey;
use Modules\Platform\Identity\Domain\Exceptions\PrivilegeEscalationException;
use Modules\Platform\Identity\Domain\Services\AuthorizationEvaluator;
use Modules\Platform\Identity\Domain\Services\FieldMasker;
use Modules\Platform\Identity\Domain\Services\MfaRequirementResolver;
use Modules\Platform\Identity\Domain\Services\PrivilegeEscalationGuard;
use Modules\Platform\Identity\Domain\ValueObjects\AbacCondition;
use Modules\Platform\Identity\Domain\ValueObjects\AbacRule;
use Modules\Platform\Identity\Domain\ValueObjects\PermissionKey;

describe('PermissionKey', function (): void {
    it('accepts standard and domain actions', function (): void {
        expect(PermissionKey::from('invoicing.invoices.approve')->value())->toBe('invoicing.invoices.approve')
            ->and(PermissionKey::isValid('crm.contacts.view'))->toBeTrue();
    });

    it('rejects malformed keys', function (string $key): void {
        expect(PermissionKey::isValid($key))->toBeFalse();
        expect(fn () => PermissionKey::from($key))->toThrow(InvalidPermissionKey::class);
    })->with(['crm.contacts', 'CRM.contacts.view', 'crm.contacts.fly', 'a.b.c.view', '']);
});

describe('AuthorizationEvaluator', function (): void {
    $evaluator = new AuthorizationEvaluator;

    it('denies by default', function () use ($evaluator): void {
        expect($evaluator->allows('crm.contacts.view', []))->toBeFalse();
    });

    it('grants through the union of role permissions and wildcards', function () use ($evaluator): void {
        expect($evaluator->allows('crm.contacts.view', ['crm.contacts.view']))->toBeTrue()
            ->and($evaluator->allows('crm.contacts.delete', ['crm.*']))->toBeTrue()
            ->and($evaluator->allows('hr.payroll.view', ['crm.*']))->toBeFalse();
    });

    it('lets an explicit deny override a grant', function () use ($evaluator): void {
        $deny = new AbacRule('document.total', 'deny', []);
        $rule = new AbacRule('invoicing.invoices.approve', 'deny', [new AbacCondition('document.total', '>', 10000)]);

        expect($evaluator->allows('invoicing.invoices.approve', ['invoicing.invoices.approve'], [$rule], ['document.total' => 20000]))->toBeFalse()
            ->and($evaluator->allows('invoicing.invoices.approve', ['invoicing.invoices.approve'], [$rule], ['document.total' => 500]))->toBeTrue()
            ->and($deny->matches([]))->toBeFalse();
    });

    it('requires an allow rule to match when one is defined', function () use ($evaluator): void {
        $rule = new AbacRule('crm.contacts.update', 'allow', [new AbacCondition('contact.owner_id', '==', 'u1')]);

        expect($evaluator->allows('crm.contacts.update', ['crm.contacts.update'], [$rule], ['contact.owner_id' => 'u1']))->toBeTrue()
            ->and($evaluator->allows('crm.contacts.update', ['crm.contacts.update'], [$rule], ['contact.owner_id' => 'u2']))->toBeFalse();
    });

    it('denies safely when an attribute is missing', function () use ($evaluator): void {
        $allow = new AbacRule('crm.contacts.update', 'allow', [new AbacCondition('contact.region', 'in', ['TI'])]);

        expect($evaluator->allows('crm.contacts.update', ['crm.contacts.update'], [$allow], []))->toBeFalse();
    });
});

describe('AbacCondition', function (): void {
    it('rejects attributes and operators outside the allowlist', function (): void {
        expect((new AbacCondition('user.password', '==', 'x'))->matches(['user.password' => 'x']))->toBeFalse()
            ->and((new AbacCondition('company_id', 'matches', 'x'))->matches(['company_id' => 'x']))->toBeFalse();
    });

    it('evaluates comparison and list operators', function (): void {
        expect((new AbacCondition('document.total', '>=', 100))->matches(['document.total' => '100.00']))->toBeTrue()
            ->and((new AbacCondition('contact.region', 'not_in', ['TI']))->matches(['contact.region' => 'ZH']))->toBeTrue()
            ->and((new AbacCondition('contact.region', 'in', 'TI'))->matches(['contact.region' => 'TI']))->toBeFalse();
    });
});

describe('PrivilegeEscalationGuard', function (): void {
    $guard = new PrivilegeEscalationGuard;

    it('blocks self modification', function () use ($guard): void {
        expect(fn () => $guard->assertCanAssign('u1', 'u1', [SystemRole::Owner], [SystemRole::Member], [], 1))
            ->toThrow(PrivilegeEscalationException::class);
    });

    it('blocks assigning a role above the actor', function () use ($guard): void {
        expect(fn () => $guard->assertCanAssign('a', 'b', [SystemRole::Accountant], [SystemRole::Admin], [], 1))
            ->toThrow(PrivilegeEscalationException::class);
    });

    it('returns 409 when removing the last owner', function () use ($guard): void {
        try {
            $guard->assertCanAssign('a', 'b', [SystemRole::Owner], [SystemRole::Member], [SystemRole::Owner], 0);
            $this->fail('Expected exception');
        } catch (PrivilegeEscalationException $e) {
            expect($e->httpStatus)->toBe(409);
        }
    });

    it('allows a legitimate assignment', function () use ($guard): void {
        $guard->assertCanAssign('a', 'b', [SystemRole::Admin], [SystemRole::Sales], [SystemRole::Member], 1);
        expect(true)->toBeTrue();
    });
});

describe('FieldMasker', function (): void {
    $masker = new FieldMasker;

    it('masks IBANs keeping country and last four', function () use ($masker): void {
        expect($masker->maskIban('CH93 0076 2011 6238 5295 7'))->toBe('CH** **** **** **** *295 7')
            ->and(str_replace(' ', '', $masker->maskIban('IT60X0542811101000000123456')))->toStartWith('IT')->toEndWith('3456')
            ->and($masker->maskIban('x'))->toBe('•••');
    });

    it('masks amounts and drops hidden fields', function () use ($masker): void {
        $result = $masker->apply(
            ['name' => 'Anna', 'salary' => 5000, 'iban' => 'CH9300762011623852957', 'ssn' => '756.1234'],
            ['salary' => FieldVisibility::Masked, 'iban' => FieldVisibility::Masked, 'ssn' => FieldVisibility::Hidden, 'name' => FieldVisibility::Visible],
            ['iban' => 'iban'],
        );

        expect($result['data'])->toMatchArray(['name' => 'Anna', 'salary' => '•••'])
            ->and($result['data'])->not->toHaveKey('ssn')
            ->and($result['masked_fields'])->toBe(['salary', 'iban'])
            ->and(json_encode($result['data']))->not->toContain('5000')->not->toContain('9300762');
    });
});

describe('MfaRequirementResolver', function (): void {
    $resolver = new MfaRequirementResolver;

    it('forces 2FA for owner and admin regardless of policy', function () use ($resolver): void {
        expect($resolver->isRequired([SystemRole::Admin], false))->toBeTrue()
            ->and($resolver->canDisable([SystemRole::Owner], false))->toBeFalse();
    });

    it('defers to the tenant policy for other roles', function () use ($resolver): void {
        expect($resolver->isRequired([SystemRole::Sales], false))->toBeFalse()
            ->and($resolver->isRequired([SystemRole::Sales], true))->toBeTrue();
    });
});

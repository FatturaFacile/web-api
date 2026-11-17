<?php

declare(strict_types=1);

return [
    'errors' => [
        'user_limit' => 'The plan of the company allows at most :max active people, invitations included.',
        'role_limit' => 'The plan of the company allows at most :max custom roles.',
        'role_in_use' => 'Members still hold this role: give them another role first.',
        'delegator_not_member' => 'The delegator is not an active member of the company.',
        'delegate_not_member' => 'The delegate is not an active member of the company.',
        'delegate_is_delegator' => 'A person cannot delegate to themselves.',
        'delegate_for_others' => 'Only an owner or administrator can delegate on behalf of someone else.',
        'delegate_owner_reserved' => 'Only an owner can delegate the access of an owner.',
        'team_member_not_member' => 'Every team member must be a member of the company.',
        'already_member' => 'This address already belongs to a member of the company.',
        'invitation_closed' => 'The invitation was already accepted or revoked.',
        'invitation_invalid' => 'The invitation link is not valid or has expired.',
        'invitation_sign_in' => 'Sign in with the invited account to accept this invitation.',
        'import_no_header' => 'The file needs a header row with an email column.',
        'import_too_many_rows' => 'The file has too many rows (at most :max).',
    ],
    'import' => [
        'already_member' => 'Already a member.',
        'already_invited' => 'An invitation is already pending.',
        'invalid_email' => 'The e-mail address is not valid.',
        'duplicate' => 'Repeated in the file.',
        'unknown_role' => 'Unknown role :role.',
        'unknown_team' => 'Unknown team :team.',
        'failed' => 'The row could not be imported.',
    ],
    'mail' => [
        'invitation' => [
            'subject' => 'Invitation to :company - :app',
            'greeting' => 'You have been invited',
            'body' => ':inviter invited you to join :company on :app as :role.',
            'action' => 'Accept the invitation',
            'expires' => 'The link is valid until :date.',
        ],
    ],
];

<?php

namespace App\Modules\Staff\Console;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Services\StaffManagementService;
use App\Modules\Staff\Support\EmailRules;
use App\Modules\Staff\Support\PasswordRules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Create an administrator from the command line, e.g. the first one on a fresh install (FR-025).
 *
 * The password is typed with hidden input and is never an option, so it cannot end up in shell
 * history (research R12).
 */
class CreateAdministratorCommand extends Command
{
    protected $signature = 'staff:create-admin
                            {--name= : Full name}
                            {--email= : Email address}';

    protected $description = 'Create an active administrator account';

    public function handle(StaffManagementService $staff): int
    {
        $data = [
            'name' => trim((string) ($this->option('name') ?? $this->ask(__('staff::messages.prompt_name')))),
            'email' => EmailRules::normalize($this->option('email') ?? $this->ask(__('staff::messages.prompt_email'))),
            'password' => (string) $this->secret(__('staff::messages.prompt_password')),
            'password_confirmation' => (string) $this->secret(__('staff::messages.prompt_password_confirmation')),
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', ...EmailRules::rules(), Rule::unique('users', 'email')],
            'password' => PasswordRules::rules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = $staff->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => Role::Administrator->value,
            'password' => $data['password'],
        ]);

        $this->info(__('staff::messages.administrator_created', ['email' => $admin->email]));

        return self::SUCCESS;
    }
}

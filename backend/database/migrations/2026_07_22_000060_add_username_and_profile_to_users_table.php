<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
            $table->string('first_name')->nullable()->after('username');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('mobile', 40)->nullable()->after('last_name');
        });

        $users = DB::table('users')->orderBy('id')->get();
        $used = [];

        foreach ($users as $user) {
            $base = 'user'.$user->id;
            if (! empty($user->email) && str_contains((string) $user->email, '@')) {
                $local = strstr((string) $user->email, '@', true);
                if (is_string($local) && $local !== '') {
                    $sanitized = preg_replace('/[^a-zA-Z0-9_-]/', '', $local);
                    if (is_string($sanitized) && $sanitized !== '') {
                        $base = $sanitized;
                    }
                }
            }

            $username = $base;
            $suffix = 1;
            while (isset($used[$username])) {
                $username = $base.$suffix;
                $suffix++;
            }
            $used[$username] = true;

            $firstName = $user->first_name ?? null;
            $lastName = $user->last_name ?? null;
            if (empty($firstName) && empty($lastName) && ! empty($user->name)) {
                $parts = preg_split('/\s+/u', trim((string) $user->name), 2) ?: [];
                $firstName = $parts[0] ?? null;
                $lastName = $parts[1] ?? null;
            }

            DB::table('users')->where('id', $user->id)->update([
                'username' => $username,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'first_name', 'last_name', 'mobile']);
        });
    }
};

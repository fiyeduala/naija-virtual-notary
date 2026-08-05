<?php

use App\Models\NotaryBankDetail;
use App\Support\Banks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

/**
 * Accounts saved before the rework hold a typed bank NAME and no code, which
 * means they cannot be paid to. Match them back to a code where the name is
 * unambiguous, so those notaries only have to re-verify rather than re-enter.
 *
 * Anything that does not match cleanly is left alone: a wrong code sends money
 * to the wrong bank, so a blank that forces a human to choose is far safer than
 * a guess. Those rows show as "No account" in the admin panel until fixed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $banks = collect(Banks::all())
            ->mapWithKeys(fn (string $name, string $code) => [$this->normalize($name) => $code]);

        NotaryBankDetail::whereNull('bank_code')->whereNotNull('bank_name')->each(
            function (NotaryBankDetail $detail) use ($banks) {
                $code = $banks->get($this->normalize((string) $detail->bank_name));

                if ($code === null) {
                    return;
                }

                // The account itself is still unverified — only the bank is now
                // addressable. Verification happens on the next save.
                $detail->forceFill([
                    'bank_code' => $code,
                    'bank_name' => Banks::name($code),
                ])->save();
            },
        );
    }

    public function down(): void
    {
        // Nothing to undo — the column is dropped by the migration that added it.
    }

    /** "United Bank for Africa" and "UNITED BANK FOR AFRICA" are the same bank. */
    private function normalize(string $name): string
    {
        return Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->value();
    }
};

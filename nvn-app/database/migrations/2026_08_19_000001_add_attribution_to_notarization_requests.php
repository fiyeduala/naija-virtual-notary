<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an ad click is remembered.
 *
 * Meta identifies a conversion by the click id it put in the landing URL, and
 * that id only exists in the client's browser. A payment settled by the
 * Paystack webhook arrives server to server with no browser attached, and a
 * bank transfer is recorded days later by an admin sitting at a different
 * computer entirely — so unless the click is written down at the moment the
 * client is in front of us, the conversion can never be matched to the ad that
 * produced it. Meta does not accept backfills, so there is no second chance.
 *
 * On the request rather than on the payment because a request is what an ad
 * actually buys: the client may open checkout twice, abandon it, and settle by
 * transfer, and all of that is one click on one advert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->json('attribution')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notarization_requests', function (Blueprint $table) {
            $table->dropColumn('attribution');
        });
    }
};

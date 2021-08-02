<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAliItemDetailsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('ali_item_details', function (Blueprint $table) {
            $table->id();
            $table->string('store_id')->index();
            $table->string('store_name');
            $table->string('item_id')->unique();
            $table->text('item_name');
            $table->integer('price');
            $table->integer('org_price');
            $table->integer('discount_price');
            $table->integer('discount_per');
            $table->string('colors');
            $table->string('sizes');
            $table->string('review_point');
            $table->integer('review_count');
            $table->integer('sales');
            $table->text('images');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('ali_item_details');
    }
}

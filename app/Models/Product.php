<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    /**
     * Get the farms for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function farms()
    {
        return $this->hasMany(Farm::class);
    }

    /**
     * Get the product types for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function productTypes()
    {
        return $this->hasMany(ProductType::class);
    }
}

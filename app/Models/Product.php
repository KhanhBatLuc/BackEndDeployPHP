<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'category_id',
        'brand_id',
    ];
    public function Category()
    {
        return $this->belongsTo(Category::class);
    }
    public function Brand()
    {
        return $this->belongsTo(Brand::class);
    }
    public function ProductImages()
    {
        return $this->hasMany(ProductImage::class);
    }
    public function product_variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
    public function getProductVariantCountAttribute()
    {
        return $this->product_variants()->count(); // Đếm số lượng flash sale products
    }
    protected $appends = ['ProductVariantCount'];
    protected $with = ['ProductImages','Category','Brand'];
 
}

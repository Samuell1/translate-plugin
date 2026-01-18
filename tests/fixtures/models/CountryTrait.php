<?php namespace RainLab\Translate\Tests\Fixtures\Models;

use Model;

/**
 * CountryTrait Model - Uses the Translatable trait instead of behavior
 */
class CountryTrait extends Model
{
    use \RainLab\Translate\Traits\Translatable;

    public $translatable = [['name', 'index' => true], 'states'];

    /**
     * @var string The database table used by the model.
     */
    public $table = 'translate_test_countries';

    /**
     * @var array Guarded fields
     */
    protected $guarded = [];

    /**
     * @var array Jsonable fields
     */
    protected $jsonable = ['states'];
}

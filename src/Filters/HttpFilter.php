<?php

declare(strict_types=1);

namespace Orchid\Filters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class HttpFilter
{
    /**
     * Column names are alphanumeric strings that can contain
     * underscores (`_`) but can't start with a number.
     */
    private const VALID_COLUMN_NAME_REGEX = '/^(?![\d])[A-Za-z0-9_>-]*$/';
    /**
     * @var Request
     */
    protected $request;
    /**
     * @var Collection
     */
    protected $filters;
    /**
     * @var Collection
     */
    protected $sorts;
    /**
     * Model options and allowed params.
     *
     * @var Collection
     */
    protected $options;
    
    /**
     * Filter constructor.
     *
     * @param Request|null $request
     */
    public function __construct(Request $request = null)
    {
        $this->request = $request ?? request();

        $this->filters = $this->request->collect('filter')->map(function ($item) {
            return $this->parseHttpValue($item);
        });
        
        $this->sorts = collect($this->request->get('sort', []));
    }
    
    /**
     * @param string|null|array $query
     *
     * @return string|array|null
     */
    protected function parseHttpValue($query)
    {
        if (is_string($query)) {
            $item = explode(',', $query);
            
            if (count($item) > 1) {
                return $item;
            }
        }
        
        return $query;
    }
    
    /**
     * @param string $column
     *
     * @return string
     */
    public static function sanitize(string $column): string
    {
        abort_unless(preg_match(self::VALID_COLUMN_NAME_REGEX, $column), Response::HTTP_BAD_REQUEST);
        
        return $column;
    }
    
    /**
     * @param Builder $builder
     *
     * @return Builder
     */
    public function build(Builder $builder): Builder
    {
        $this->options = $builder->getModel()->getOptionsFilter();
        $this->addFiltersToQuery($builder);
        $this->addSortsToQuery($builder);
        
        return $builder;
    }
    
    /**
     * @param Builder $builder
     */
    protected function addFiltersToQuery(Builder $builder)
    {
        $allowedFilters = $this->options->get('allowedFilters')->toArray();
        
        $this->filters->each(function ($value, $property) use ($builder, $allowedFilters) {
            $allowProperty = $property;
            if (strpos($property, '.') !== false) {
                $allowProperty = strstr($property, '.', true);
            }
            
            if (in_array($allowProperty, $allowedFilters, true)) {
                $property = str_replace('.', '->', $property);
                $this->filtersExact($builder, $value, $property);
            }
        });
    }
    
    /**
     * @param Builder $query
     * @param mixed   $value
     * @param string  $property
     *
     * @return Builder
     */
    protected function filtersExact(Builder $query, $value, string $property): Builder
    {
        $property = self::sanitize($property);
        $model = $query->getModel();
        
        if ($this->isDate($model, $property)) {
            $query->when($value['start'] ?? null, function(Builder $query) use ($property, $value) {
                return $query->whereDate($property, '>=', $value['start']);
            });
            $query->when($value['end'] ?? null, function (Builder $query) use ($property, $value) {
                return $query->whereDate($property, '<=', $value['end']);
            });
        } elseif (is_array($value) && (isset($value['min']) || isset($value['max']))) {
            $query->when($value['min'] ?? null, function (Builder $query) use ($property, $value) {
                return $query->where($property, '>=', $value['min']);
            });
            $query->when($value['max'] ?? null, function (Builder $query) use ($property, $value) {
                return $query->where($property, '<=', $value['max']);
            });
        } elseif (is_array($value)) {
            $query->whereIn($property, $value);
        } elseif ($model->hasCast($property, ['bool', 'boolean'])) {
            $query->where($property, (bool)$value);
        } elseif (is_numeric($value) && !$model->hasCast($property, ['string'])) {
            $query->where($property, $value);
        } else {
            $query->where($property, 'like', "%$value%");
        }
    
        return $query;
    }
    
    /**
     * @param Builder $builder
     */
    protected function addSortsToQuery(Builder $builder)
    {
        $allowedSorts = $this->options->get('allowedSorts')->toArray();
        
        $this->sorts
            ->each(function (string $sort) use ($builder, $allowedSorts) {
                $descending = strpos($sort, '-') === 0;
                $key = ltrim($sort, '-');
                $property = Str::before($key, '.');
                $key = str_replace('.', '->', $key);
                
                if (in_array($property, $allowedSorts, true)) {
                    $key = $this->sanitize($key);
                    $builder->orderBy($key, $descending ? 'desc' : 'asc');
                }
            });
    }
    
    /**
     * @param null|string $property
     *
     * @return bool
     */
    public function isSort(string $property = null): bool
    {
        if ($property === null) {
            return $this->sorts->isEmpty();
        }
        
        if ($this->sorts->search($property, true) !== false) {
            return true;
        }
        
        if ($this->sorts->search('-' . $property, true) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * @param string $property
     *
     * @return string
     */
    public function revertSort(string $property): string
    {
        return $this->getSort($property) === 'asc'
            ? '-' . $property
            : $property;
    }
    
    /**
     * @param string $property
     *
     * @return string
     */
    public function getSort(string $property): string
    {
        return $this->sorts->search($property, true) !== false
            ? 'asc'
            : 'desc';
    }
    
    /**
     * @param string $property
     *
     * @return mixed
     */
    public function getFilter(string $property)
    {
        return Arr::get($this->filters, $property);
    }
    
    /**
     * @param Model $model
     * @param string $property
     *
     * @return bool
     */
    private function isDate(Model $model, string $property): bool
    {
        return $model->hasCast($property, ['date', 'datetime', 'immutable_date', 'immutable_datetime'])
            || in_array($property,[$model->getCreatedAtColumn(),$model->getUpdatedAtColumn()],true);
    }
}

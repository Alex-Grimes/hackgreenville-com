<?php

namespace App\Models;

use App\Http\SearchPipeline\Active;
use App\Http\SearchPipeline\Month;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Routing\Pipeline;

/**
 * @property string event_name
 * @property string title
 * @property string group_name
 * @property string description
 * @property string uri
 * @property int rsvp_count
 * @property int venue_id
 * @property Venue venue
 * @property Carbon active_at
 * @property array cache
 * @property string service
 * @property string service_id
 * @property string uniqueIdentifier
 * @method static search()
 */
class Event extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'events';

    protected $fillable
        = [
            'event_name',
            'group_name',
            'description',
            'rsvp_count',
            'active_at',
            'expire_at',
            'cancelled_at',
            'uri',
            'venue_id',
            'cache',
            'event_uuid',
            'service',
            'service_id',
        ];

    protected $casts
        = [
            'cache'      => 'json',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
            'active_at'  => 'datetime',
            'expire_at'  => 'datetime',
        ];

    protected $appends
        = [
            'short_description',
            'title',
            'active_at_ftm',
        ];

    public function getUniqueIdentifierAttribute(): bool|string
    {
        $service    = $this->service;
        $service_id = $this->service_id;

        return json_encode(compact('service', 'service_id'));
    }

    public function venue(): BelongsTo
    {
        return $this->belongsTo(Venue::class);
    }

    public function scopeGetActive(Builder $query): Builder
    {
        return $query
            ->where('active_at', '>=', DB::raw('NOW()'))
            ->orderBy('active_at', 'asc');
    }

    public function scopeStartOfMonth(Builder $query): Builder
    {
        return $query
            ->where('active_at', '>=', date('Y-m-1'))
            ->orderBy('active_at', 'asc');
    }

    public function scopeDatesBetween(Builder $query, $start, $end): Builder
    {
        return $query
            ->whereBetween(
                DB::raw('DATE(`active_at`)'),
                [
                    date('Y-m-d', strtotime($start)),
                    date('Y-m-d', strtotime($end)),
                ],
            );
    }

    public function scopeSearch(Builder $query)
    {
        return app(Pipeline::class)
            ->send($query)
            ->through(
                [
                    // Get the active events
                    Active::class,
                    Month::class,
                ],
            )
            ->thenReturn();
    }

    /**
     * accessor url to uri
     * @return string
     */
    public function getUrlAttribute(): string
    {
        return $this->uri;
    }

    public function getStateAttribute(): string
    {
        if ($this->active_at->isPast()) {
            return 'passed';
        }


        return 'upcoming';
    }

    /**
     * build out the link that adds this event to the users personal calendar
     * @return string
     */
    public function getGCalUrlAttribute(): string
    {
        $event_time = $this->active_at->format('Y-m-d\TH:i:s\Z');

        $start_time = $this->active_at->format('Ymd\THis\Z');

        // Assume event is two hours long...
        $end_time = $this->active_at->addHours(2)->format('Ymd\THis\Z');

        $location = '';

        if (property_exists($this, 'venue') && ($this->venue !== null)) {
            $location .= $this->venue->name . ', ';
            $location .= $this->venue->address . ', ';
            $location .= $this->venue->city . ', ';
            $location .= $this->venue->state;
        }

        $calendar_url = "http://www.google.com/calendar/event?action=TEMPLATE&";
        $calendar_url .= 'text=' . urlencode($this->event_name) . '&';
        $calendar_url .= "dates={$start_time}/{$end_time}&";
        $calendar_url .= 'details=' . urlencode(strip_tags($this->description)) . '&';
        $calendar_url .= 'location=' . urlencode($location) . '&';
        $calendar_url .= "trp=false&";

        return $calendar_url;
    }

    public function getLocalActiveAtAttribute(): Carbon|string
    {
        return $this->active_at->tz(config('app.timezone'));
    }

    public function getDescriptionAttribute(): array|string
    {
        return str_replace('<a', '<a target="_blank"', $this->attributes['description']);
    }

    public function getShortDescriptionAttribute(): string
    {
        return str_limit($this->description);
    }

    public function getActiveAtFtmAttribute(): string
    {
        return $this->active_at->diffForHumans();
    }

    public function getTitleAttribute(): string
    {
        return $this->event_name;
    }
}

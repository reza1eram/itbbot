@php($labels = ['draft' => 'پیش‌نویس', 'scheduled' => 'زمان‌بندی‌شده', 'open' => 'باز', 'closed' => 'بسته', 'archived' => 'بایگانی'])
<span class="badge badge-{{ $status }}">{{ $labels[$status] ?? $status }}</span>

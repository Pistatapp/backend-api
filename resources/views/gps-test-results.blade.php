<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GPS Data Analysis Results</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 32px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 25px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .label {
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 10px;
        }

        .stat-card .value {
            color: #333;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .stat-card .subvalue {
            color: #999;
            font-size: 14px;
        }

        .stat-card.movement {
            border-left: 4px solid #10b981;
        }

        .stat-card.stoppage {
            border-left: 4px solid #ef4444;
        }

        .stat-card.info {
            border-left: 4px solid #3b82f6;
        }

        .stat-card.activation {
            border-left: 4px solid #8b5cf6;
            background: linear-gradient(135deg, #ffffff 0%, #f5f3ff 100%);
        }

        .section {
            background: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 20px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .section h2 {
            color: #333;
            margin-bottom: 20px;
            font-size: 24px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
        }

        .item-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .movement-item {
            background: #f0fdf4;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #10b981;
        }

        .stoppage-item {
            background: #fef2f2;
            border-radius: 8px;
            padding: 20px;
            border-left: 4px solid #6366f1;
        }

        .stoppage-item.status-off {
            border-left-color: #dc2626;
        }

        .stoppage-item.ignored {
            background: #f3f4f6;
            border-left-color: #9ca3af;
            opacity: 0.8;
        }

        .item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .item-index {
            font-size: 18px;
            font-weight: bold;
            color: #333;
        }

        .item-value {
            font-size: 20px;
            font-weight: bold;
            color: #6366f1;
        }

        .movement-item .item-value {
            color: #10b981;
        }

        .stoppage-item.status-off .item-value {
            color: #dc2626;
        }

        .stoppage-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            color: #666;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }

        .detail-value {
            color: #333;
            font-size: 14px;
            font-weight: 500;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-badge.on {
            background: #d1fae5;
            color: #065f46;
        }

        .status-badge.off {
            background: #fee2e2;
            color: #991b1b;
        }

        .ignored-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            background: #e5e7eb;
            color: #4b5563;
            margin-left: 10px;
        }

        .api-link {
            background: #3b82f6;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
            font-weight: 600;
            transition: background 0.2s;
        }

        .api-link:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🚜 GPS Data Analysis Results</h1>
            <p>Analysis Period: {{ $results['start_time'] }} to {{ $results['end_time'] }}</p>
            <p>Total Records Analyzed: {{ number_format($results['total_records']) }}</p>
            <a href="/api/gps-test" class="api-link" target="_blank">📊 View JSON API Response</a>
        </div>

        <!-- Statistics Grid -->
        <div class="stats-grid">
            <!-- Device On Time -->
            @if($results['device_on_time'])
            <div class="stat-card activation">
                <div class="label">⚡ Device Turned ON</div>
                <div class="value">{{ \Carbon\Carbon::parse($results['device_on_time'])->format('H:i:s') }}</div>
                <div class="subvalue">{{ \Carbon\Carbon::parse($results['device_on_time'])->format('Y-m-d H:i:s') }}</div>
            </div>
            @endif

            <!-- First Movement Time -->
            @if($results['first_movement_time'])
            <div class="stat-card activation">
                <div class="label">🚀 First Movement</div>
                <div class="value">{{ \Carbon\Carbon::parse($results['first_movement_time'])->format('H:i:s') }}</div>
                <div class="subvalue">{{ \Carbon\Carbon::parse($results['first_movement_time'])->format('Y-m-d H:i:s') }}</div>
            </div>
            @endif

            <!-- Movement Distance -->
            <div class="stat-card movement">
                <div class="label">Movement Distance</div>
                <div class="value">{{ number_format($results['movement_distance_km'], 2) }} km</div>
                <div class="subvalue">{{ number_format($results['movement_distance_meters'], 2) }} meters</div>
            </div>

            <!-- Movement Duration -->
            <div class="stat-card movement">
                <div class="label">Movement Duration</div>
                <div class="value">{{ $results['movement_duration_formatted'] }}</div>
                <div class="subvalue">{{ number_format($results['movement_duration_seconds']) }} seconds</div>
                @if($results['ignored_stoppage_duration_seconds'] > 0)
                    <div class="subvalue" style="font-size: 11px; margin-top: 5px; color: #6b7280;">
                        (includes {{ $results['ignored_stoppage_duration_formatted'] }} from ignored stoppages)
                    </div>
                @endif
            </div>

            <!-- Stoppage Count -->
            <div class="stat-card stoppage">
                <div class="label">Stoppage Count</div>
                <div class="value">{{ $results['stoppage_count'] }}</div>
                <div class="subvalue">Valid stoppages (≥60s)</div>
            </div>

            <!-- Ignored Stoppage Count -->
            <div class="stat-card info">
                <div class="label">Ignored Stoppages</div>
                <div class="value">{{ $results['ignored_stoppage_count'] }}</div>
                <div class="subvalue">{{ $results['ignored_stoppage_duration_formatted'] }} total (&lt;60s each)</div>
            </div>

            <!-- Total Stoppage Duration -->
            <div class="stat-card stoppage">
                <div class="label">Total Stoppage Duration</div>
                <div class="value">{{ $results['stoppage_duration_formatted'] }}</div>
                <div class="subvalue">{{ number_format($results['stoppage_duration_seconds']) }} seconds</div>
            </div>

            <!-- Stoppage While ON -->
            <div class="stat-card info">
                <div class="label">Stoppage While ON</div>
                <div class="value">{{ $results['stoppage_duration_while_on_formatted'] }}</div>
                <div class="subvalue">{{ number_format($results['stoppage_duration_while_on_seconds']) }} seconds</div>
            </div>

            <!-- Stoppage While OFF -->
            <div class="stat-card info">
                <div class="label">Stoppage While OFF</div>
                <div class="value">{{ $results['stoppage_duration_while_off_formatted'] }}</div>
                <div class="subvalue">{{ number_format($results['stoppage_duration_while_off_seconds']) }} seconds</div>
            </div>
        </div>

        <!-- Detailed Movement/Stoppage Information (Chronological Order) -->
        <div class="section">
            <h2>📊 Detailed Movement/Stoppage Information</h2>
            <p style="color: #666; margin-bottom: 10px;">
                Events shown in chronological order |
                Movement: speed &gt; 2 km/h |
                Stopped: speed ≤ 2 km/h |
                Ignored stoppages: &lt; 60 seconds
            </p>
            <p style="color: #888; margin-bottom: 20px; font-size: 13px; font-style: italic;">
                Note: Transition points are included in the previous segment
                (first stopped point = last movement point, first moving point = last stoppage point)
            </p>

            @if(count($chronological) > 0)
                <div class="item-list">
                    @foreach($chronological as $item)
                        @if($item['type'] === 'movement')
                            <!-- Movement Item -->
                            <div class="movement-item">
                                <div class="item-header">
                                    <div class="item-index">🚜 Movement #{{ $item['index'] }}</div>
                                    <div class="item-value">{{ $item['distance_km'] }} km</div>
                                </div>

                                <div class="stoppage-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Start Time</div>
                                        <div class="detail-value">{{ $item['start_time'] }}</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">End Time</div>
                                        <div class="detail-value">{{ $item['end_time'] }}</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Duration</div>
                                        <div class="detail-value">{{ $item['duration_formatted'] }} ({{ number_format($item['duration_seconds']) }}s)</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Distance</div>
                                        <div class="detail-value">{{ number_format($item['distance_meters'], 2) }} m</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Avg Speed</div>
                                        <div class="detail-value">{{ number_format($item['avg_speed'], 2) }} km/h</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Start Location</div>
                                        <div class="detail-value">
                                            {{ number_format($item['start_location']['latitude'], 6) }},
                                            {{ number_format($item['start_location']['longitude'], 6) }}
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">End Location</div>
                                        <div class="detail-value">
                                            {{ number_format($item['end_location']['latitude'], 6) }},
                                            {{ number_format($item['end_location']['longitude'], 6) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @else
                            <!-- Stoppage Item -->
                            <div class="stoppage-item {{ $item['status'] === 'off' ? 'status-off' : '' }} {{ $item['ignored'] ? 'ignored' : '' }}">
                                <div class="item-header">
                                    <div class="item-index">
                                        🛑 Stoppage #{{ $item['index'] }}
                                        @if($item['ignored'])
                                            <span class="ignored-badge">Ignored (&lt; 60s)</span>
                                        @endif
                                    </div>
                                    <div class="item-value">{{ $item['duration_formatted'] }}</div>
                                </div>

                                <div class="stoppage-details">
                                    <div class="detail-item">
                                        <div class="detail-label">Start Time</div>
                                        <div class="detail-value">{{ $item['start_time'] }}</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">End Time</div>
                                        <div class="detail-value">{{ $item['end_time'] }}</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Duration</div>
                                        <div class="detail-value">{{ number_format($item['duration_seconds']) }} seconds</div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Location</div>
                                        <div class="detail-value">
                                            {{ number_format($item['location']['latitude'], 6) }},
                                            {{ number_format($item['location']['longitude'], 6) }}
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Status</div>
                                        <div class="detail-value">
                                            <span class="status-badge {{ $item['status'] }}">
                                                {{ strtoupper($item['status']) }}
                                            </span>
                                        </div>
                                    </div>

                                    @if($item['ignored'])
                                        <div class="detail-item">
                                            <div class="detail-label">Note</div>
                                            <div class="detail-value" style="color: #6b7280; font-style: italic;">
                                                Counted as movement (duration &lt; 60 seconds)
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @else
                <p style="color: #666;">No data available.</p>
            @endif
        </div>
    </div>
</body>
</html>


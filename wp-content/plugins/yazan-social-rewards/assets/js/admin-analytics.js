/**
 * Yazan Rewards — Analytics dashboard.
 *
 * Read-only KPIs, self-contained inline-SVG charts (no external library) and
 * per-section CSV export, driven by the yazan-rewards/v1/admin/analytics API.
 *
 * Palette is dataviz-validated (see the skill's validate_palette.js):
 *   series #1668a8 (issued/primary) + #c0492b (spent) pass CVD + chroma + contrast;
 *   tiers use a single-hue amber SEQUENTIAL ramp (ordinal by slice size) + labels.
 * All data reaches the DOM via textContent — never innerHTML — so titles/labels
 * cannot inject markup.
 */
( function () {
	'use strict';

	var cfg  = window.YazanAnalytics || {};
	var root = document.getElementById( 'yzrw-analytics-app' );
	if ( ! cfg.restUrl || ! root ) { return; }

	var SVGNS = 'http://www.w3.org/2000/svg';
	var range = '30';
	var data  = null;

	/* Validated palette (text always uses ink tokens, never a series colour). */
	var C = {
		issued:  '#1668a8',
		spent:   '#c0492b',
		bar:     '#1668a8',
		ink:     '#1d2327',
		ink2:    '#50575e',
		ink3:    '#787c82',
		grid:    '#e6e7e8',
		axis:    '#c3c4c7',
		surface: '#ffffff'
	};
	/* Amber sequential endpoints (light -> dark); monotonic lightness. */
	var AMBER_HI = [ 233, 205, 143 ]; // #e9cd8f
	var AMBER_LO = [ 122, 84, 24 ];   // #7a5418

	/* ---- tiny helpers ---- */

	function el( t, a, c ) {
		var n = document.createElement( t );
		a = a || {};
		Object.keys( a ).forEach( function ( k ) {
			if ( k === 'text' ) { n.textContent = a[ k ]; }
			else { n.setAttribute( k, a[ k ] ); }
		} );
		( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } );
		return n;
	}
	function s( t, a, c ) {
		var n = document.createElementNS( SVGNS, t );
		a = a || {};
		Object.keys( a ).forEach( function ( k ) { n.setAttribute( k, a[ k ] ); } );
		( c || [] ).forEach( function ( x ) { if ( x ) { n.appendChild( x ); } } );
		return n;
	}
	function stext( x, y, str, opts ) {
		opts = opts || {};
		var n = s( 'text', {
			x: x, y: y,
			'text-anchor': opts.anchor || 'start',
			'dominant-baseline': opts.baseline || 'auto',
			'font-size': opts.size || 11,
			'font-weight': opts.weight || 400,
			fill: opts.fill || C.ink3
		} );
		n.textContent = str;
		return n;
	}
	function api( path ) {
		return fetch( cfg.restUrl + path, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce }
		} ).then( function ( r ) {
			return r.json().then( function ( j ) {
				if ( ! r.ok ) { throw new Error( ( j && j.message ) || 'Error' ); }
				return j;
			} );
		} );
	}

	function fmt( n )   { return Number( n || 0 ).toLocaleString(); }
	function money( v ) { return ( cfg.currency || '' ) + Number( v || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
	function pct( r )   { return ( Number( r || 0 ) * 100 ).toFixed( 1 ) + '%'; }
	function md( d )    { return String( d ).slice( 5, 7 ) + '/' + String( d ).slice( 8, 10 ); }
	function amber( f ) {
		f = Math.max( 0, Math.min( 1, f ) );
		var r = Math.round( AMBER_HI[0] + ( AMBER_LO[0] - AMBER_HI[0] ) * f );
		var g = Math.round( AMBER_HI[1] + ( AMBER_LO[1] - AMBER_HI[1] ) * f );
		var b = Math.round( AMBER_HI[2] + ( AMBER_LO[2] - AMBER_HI[2] ) * f );
		return 'rgb(' + r + ',' + g + ',' + b + ')';
	}
	function clear( n ) { while ( n.firstChild ) { n.removeChild( n.firstChild ); } }

	/* ---- toolbar (range filter, one row above the charts) ---- */

	function toolbar() {
		var bar = el( 'div', { class: 'yzrw-toolbar' } );
		var group = el( 'div', { class: 'yzrw-range', role: 'group', 'aria-label': 'Date range' } );
		[ [ '30', '30 days' ], [ '90', '90 days' ], [ '365', '1 year' ], [ 'all', 'All time' ] ].forEach( function ( r ) {
			var b = el( 'button', { class: 'button' + ( range === r[0] ? ' button-primary' : '' ), text: r[1] } );
			b.addEventListener( 'click', function () { if ( range !== r[0] ) { range = r[0]; load(); } } );
			group.appendChild( b );
		} );
		bar.appendChild( group );
		var refresh = el( 'button', { class: 'button', text: 'Refresh' } );
		refresh.addEventListener( 'click', load );
		bar.appendChild( refresh );
		return bar;
	}

	/* ---- KPI cards ---- */

	function card( label, value, sub ) {
		return el( 'div', { class: 'yzrw-kpi' }, [
			el( 'div', { class: 'yzrw-kpi-label', text: label } ),
			el( 'div', { class: 'yzrw-kpi-value', text: value } ),
			sub ? el( 'div', { class: 'yzrw-kpi-sub', text: sub } ) : null
		] );
	}
	function kpiGroup( title, cards ) {
		return el( 'div', { class: 'yzrw-card' }, [
			el( 'h2', { text: title } ),
			el( 'div', { class: 'yzrw-kpi-grid' }, cards )
		] );
	}

	function kpiSection() {
		var cu = data.customers, ca = data.campaigns, b = data.business;
		var wrap = el( 'div' );
		wrap.appendChild( kpiGroup( 'Customers', [
			card( 'Total customers', fmt( cu.total ) ),
			card( 'VIP customers', fmt( cu.vip ), 'Top loyalty tiers' ),
			card( 'Active ambassadors', fmt( cu.active_ambassadors ) ),
			card( 'Purchasing customers', fmt( b.purchasing_customers ) )
		] ) );
		wrap.appendChild( kpiGroup( 'Business', [
			card( 'Customer retention', pct( b.retention_rate ), 'Repeat-purchase rate' ),
			card( 'Lifetime value', money( b.clv ), 'Avg / purchasing customer' ),
			card( 'Referral revenue', money( b.referral_revenue ) ),
			card( 'Reward cost', money( b.reward_cost.value ), fmt( b.reward_cost.points ) + ' pts · ' + fmt( b.reward_cost.redemptions ) + ' redemptions' ),
			card( 'Point liability', money( b.point_liability.total_value ), fmt( b.point_liability.points_outstanding ) + ' pts outstanding' )
		] ) );
		wrap.appendChild( kpiGroup( 'Campaigns', [
			card( 'Campaigns', fmt( ca.total_campaigns ) ),
			card( 'Participation', fmt( ca.participation ) ),
			card( 'Content generated', fmt( ca.content_generated ), 'Submissions' ),
			card( 'Avg engagement', pct( ca.engagement_rate ) )
		] ) );
		return wrap;
	}

	/* ---- line / area chart: points issued vs spent ---- */

	function lineChart() {
		var series = [
			{ label: 'Points issued', color: C.issued, data: data.series.points_issued || [] },
			{ label: 'Points spent',  color: C.spent,  data: data.series.points_spent  || [] }
		];
		var W = 760, H = 280, m = { t: 26, r: 116, b: 34, l: 56 };
		var iw = W - m.l - m.r, ih = H - m.t - m.b;

		var dset = {};
		series.forEach( function ( se ) { se.data.forEach( function ( p ) { dset[ p.date ] = 1; } ); } );
		var dates = Object.keys( dset ).sort();

		var box = el( 'div', { class: 'yzrw-card' }, [ el( 'h2', { text: 'Points issued vs spent' } ) ] );
		box.appendChild( legend( series ) );
		if ( ! dates.length ) { box.appendChild( emptyNote() ); return box; }

		var maps = series.map( function ( se ) {
			var mp = {}; se.data.forEach( function ( p ) { mp[ p.date ] = p.value; } ); return mp;
		} );
		var maxV = 0;
		dates.forEach( function ( d ) { maps.forEach( function ( mp ) { maxV = Math.max( maxV, mp[ d ] || 0 ); } ); } );
		if ( maxV <= 0 ) { maxV = 1; }

		var n = dates.length;
		function X( i ) { return m.l + ( n === 1 ? iw / 2 : ( i / ( n - 1 ) ) * iw ); }
		function Y( v ) { return m.t + ih - ( v / maxV ) * ih; }

		var wrap = el( 'div', { class: 'yzrw-chart' } );
		var svg  = s( 'svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'yzrw-svg', role: 'img', 'aria-label': 'Points issued versus spent over time' } );

		/* gridlines + y labels */
		for ( var g = 0; g <= 4; g++ ) {
			var yv = maxV * g / 4, yy = Y( yv );
			svg.appendChild( s( 'line', { x1: m.l, y1: yy, x2: m.l + iw, y2: yy, stroke: C.grid, 'stroke-width': 1 } ) );
			svg.appendChild( stext( m.l - 8, yy, fmt( Math.round( yv ) ), { anchor: 'end', baseline: 'middle' } ) );
		}
		/* x labels (start / mid / end) */
		var xi = n === 1 ? [ 0 ] : [ 0, Math.floor( ( n - 1 ) / 2 ), n - 1 ];
		xi.forEach( function ( i ) {
			svg.appendChild( stext( X( i ), m.t + ih + 18, md( dates[ i ] ), { anchor: 'middle' } ) );
		} );

		/* area + line + end marker + direct label, per series */
		series.forEach( function ( se, si ) {
			var mp = maps[ si ], pts = dates.map( function ( d, i ) { return [ X( i ), Y( mp[ d ] || 0 ) ]; } );
			var line = 'M' + pts.map( function ( p ) { return p[0] + ',' + p[1]; } ).join( ' L' );
			var area = 'M' + pts[0][0] + ',' + ( m.t + ih ) + ' L' + pts.map( function ( p ) { return p[0] + ',' + p[1]; } ).join( ' L' ) + ' L' + pts[ pts.length - 1 ][0] + ',' + ( m.t + ih ) + ' Z';
			svg.appendChild( s( 'path', { d: area, fill: se.color, opacity: 0.10 } ) );
			svg.appendChild( s( 'path', { d: line, fill: 'none', stroke: se.color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' } ) );
			var last = pts[ pts.length - 1 ];
			svg.appendChild( s( 'circle', { cx: last[0], cy: last[1], r: 4, fill: se.color, stroke: C.surface, 'stroke-width': 2 } ) );
			var lv = mp[ dates[ n - 1 ] ] || 0;
			svg.appendChild( stext( last[0] + 8, last[1] - 4, se.label, { fill: C.ink2, size: 11, weight: 600 } ) );
			svg.appendChild( stext( last[0] + 8, last[1] + 9, fmt( Math.round( lv ) ) + ' pts', { fill: C.ink3, size: 10 } ) );
		} );

		/* crosshair + tooltip */
		var cross = s( 'line', { x1: 0, y1: m.t, x2: 0, y2: m.t + ih, stroke: C.axis, 'stroke-width': 1, opacity: 0, 'stroke-dasharray': '3 3' } );
		var dots  = series.map( function ( se ) { return s( 'circle', { r: 4, fill: se.color, stroke: C.surface, 'stroke-width': 2, opacity: 0 } ); } );
		svg.appendChild( cross );
		dots.forEach( function ( d ) { svg.appendChild( d ); } );
		var tip = el( 'div', { class: 'yzrw-tip' } );
		tip.style.opacity = '0';

		var hit = s( 'rect', { x: m.l, y: m.t, width: iw, height: ih, fill: 'transparent' } );
		hit.addEventListener( 'mousemove', function ( e ) {
			var rect = svg.getBoundingClientRect(), sx = W / rect.width;
			var mx = ( e.clientX - rect.left ) * sx;
			var i = n === 1 ? 0 : Math.round( ( mx - m.l ) / iw * ( n - 1 ) );
			i = Math.max( 0, Math.min( n - 1, i ) );
			var cx = X( i );
			cross.setAttribute( 'x1', cx ); cross.setAttribute( 'x2', cx ); cross.setAttribute( 'opacity', 1 );
			clear( tip );
			tip.appendChild( el( 'div', { class: 'yzrw-tip-h', text: dates[ i ] } ) );
			series.forEach( function ( se, si ) {
				dots[ si ].setAttribute( 'cx', cx ); dots[ si ].setAttribute( 'cy', Y( maps[ si ][ dates[ i ] ] || 0 ) ); dots[ si ].setAttribute( 'opacity', 1 );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-r' }, [
					el( 'span', { class: 'yzrw-swatch', style: 'background:' + se.color } ),
					el( 'span', { text: se.label + ': ' + fmt( Math.round( maps[ si ][ dates[ i ] ] || 0 ) ) } )
				] ) );
			} );
			tip.style.opacity = '1';
			tip.style.left = ( cx * rect.width / W ) + 'px';
			tip.style.top  = ( m.t * rect.height / H ) + 'px';
		} );
		hit.addEventListener( 'mouseleave', function () {
			cross.setAttribute( 'opacity', 0 );
			dots.forEach( function ( d ) { d.setAttribute( 'opacity', 0 ); } );
			tip.style.opacity = '0';
		} );
		svg.appendChild( hit );

		wrap.appendChild( svg );
		wrap.appendChild( tip );
		box.appendChild( wrap );
		return box;
	}

	/* ---- horizontal bar chart: best campaigns ---- */

	function barChart() {
		var rows = ( data.campaigns.best || [] ).slice( 0, 8 );
		var box  = el( 'div', { class: 'yzrw-card' }, [ el( 'h2', { text: 'Best campaigns' } ) ] );
		if ( ! rows.length ) { box.appendChild( emptyNote() ); return box; }

		var maxV = rows.reduce( function ( a, r ) { return Math.max( a, r.points_generated ); }, 0 ) || 1;
		var rowH = 30, gutter = 150, padR = 60, W = 520, top = 8;
		var iw = W - gutter - padR, H = top * 2 + rows.length * rowH;

		var wrap = el( 'div', { class: 'yzrw-chart' } );
		var svg  = s( 'svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'yzrw-svg', role: 'img', 'aria-label': 'Best campaigns by points generated' } );
		var tip  = el( 'div', { class: 'yzrw-tip' } ); tip.style.opacity = '0';

		rows.forEach( function ( r, i ) {
			var cy = top + i * rowH, bh = 16, by = cy + ( rowH - bh ) / 2;
			var w  = Math.max( 2, ( r.points_generated / maxV ) * iw );
			var label = r.title.length > 24 ? r.title.slice( 0, 23 ) + '…' : r.title;
			svg.appendChild( stext( gutter - 10, by + bh / 2, label, { anchor: 'end', baseline: 'middle', fill: C.ink2, size: 11 } ) );
			var bar = s( 'rect', { x: gutter, y: by, width: w, height: bh, rx: 4, ry: 4, fill: C.bar } );
			svg.appendChild( bar );
			svg.appendChild( stext( gutter + w + 8, by + bh / 2, fmt( r.points_generated ), { baseline: 'middle', fill: C.ink3, size: 11 } ) );

			function show( e ) {
				bar.setAttribute( 'opacity', 0.82 );
				clear( tip );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-h', text: r.title } ) );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-r', text: 'Points: ' + fmt( r.points_generated ) } ) );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-r', text: 'Participants: ' + fmt( r.participants ) } ) );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-r', text: 'Submissions: ' + fmt( r.submissions ) } ) );
				tip.appendChild( el( 'div', { class: 'yzrw-tip-r', text: 'Engagement: ' + pct( r.engagement_rate ) } ) );
				var rect = svg.getBoundingClientRect();
				tip.style.opacity = '1';
				tip.style.left = ( ( gutter + 8 ) * rect.width / W ) + 'px';
				tip.style.top  = ( ( cy ) * rect.height / H ) + 'px';
			}
			bar.addEventListener( 'mousemove', show );
			bar.addEventListener( 'mouseleave', function () { bar.setAttribute( 'opacity', 1 ); tip.style.opacity = '0'; } );
		} );

		wrap.appendChild( svg ); wrap.appendChild( tip ); box.appendChild( wrap );
		return box;
	}

	/* ---- donut: tier distribution (amber sequential ramp, ordinal by size) ---- */

	function polar( cx, cy, r, a ) { return { x: cx + r * Math.cos( a ), y: cy + r * Math.sin( a ) }; }
	function arc( cx, cy, ro, ri, a0, a1 ) {
		var p0 = polar( cx, cy, ro, a0 ), p1 = polar( cx, cy, ro, a1 );
		var p2 = polar( cx, cy, ri, a1 ), p3 = polar( cx, cy, ri, a0 );
		var large = ( a1 - a0 ) > Math.PI ? 1 : 0;
		return [ 'M', p0.x, p0.y, 'A', ro, ro, 0, large, 1, p1.x, p1.y, 'L', p2.x, p2.y, 'A', ri, ri, 0, large, 0, p3.x, p3.y, 'Z' ].join( ' ' );
	}

	function donutChart() {
		var tiers = ( data.customers.tiers || [] ).slice().sort( function ( a, b ) { return b.count - a.count; } );
		var box   = el( 'div', { class: 'yzrw-card' }, [ el( 'h2', { text: 'Customers by tier' } ) ] );
		var total = tiers.reduce( function ( a, t ) { return a + t.count; }, 0 );
		if ( ! total ) { box.appendChild( emptyNote() ); return box; }

		var W = 360, H = 260, cx = 120, cy = 130, ro = 96, ri = 60;
		var wrap = el( 'div', { class: 'yzrw-chart yzrw-donut-wrap' } );
		var svg  = s( 'svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'yzrw-svg', role: 'img', 'aria-label': 'Customers by loyalty tier' } );
		var tip  = el( 'div', { class: 'yzrw-tip' } ); tip.style.opacity = '0';

		// Sequential amber ramp: larger slice = deeper amber; light end clamped so the
		// smallest slice keeps contrast against the white surface (identity is also in the legend).
		var colors = tiers.map( function ( t, i ) {
			var f = tiers.length === 1 ? 0.7 : 0.4 + 0.6 * ( 1 - i / ( tiers.length - 1 ) );
			return amber( f );
		} );

		if ( tiers.length === 1 ) {
			svg.appendChild( s( 'circle', { cx: cx, cy: cy, r: ( ro + ri ) / 2, fill: 'none', stroke: colors[0], 'stroke-width': ro - ri } ) );
		} else {
			var a = -Math.PI / 2;
			tiers.forEach( function ( t, i ) {
				var a1 = a + ( t.count / total ) * Math.PI * 2;
				var path = s( 'path', { d: arc( cx, cy, ro, ri, a, a1 ), fill: colors[ i ], stroke: C.surface, 'stroke-width': 2 } );
				svg.appendChild( path );
				( function ( tt ) {
					path.addEventListener( 'mousemove', function () {
						path.setAttribute( 'opacity', 0.82 );
						clear( tip );
						tip.appendChild( el( 'div', { class: 'yzrw-tip-h', text: tt.label } ) );
						tip.appendChild( el( 'div', { class: 'yzrw-tip-r', text: fmt( tt.count ) + ' customers · ' + ( tt.count / total * 100 ).toFixed( 1 ) + '%' } ) );
						var rect = svg.getBoundingClientRect();
						tip.style.opacity = '1';
						tip.style.left = ( cx * rect.width / W ) + 'px';
						tip.style.top  = ( ( cy - ro ) * rect.height / H ) + 'px';
					} );
					path.addEventListener( 'mouseleave', function () { path.setAttribute( 'opacity', 1 ); tip.style.opacity = '0'; } );
				} )( t );
				a = a1;
			} );
		}
		svg.appendChild( stext( cx, cy - 6, fmt( total ), { anchor: 'middle', baseline: 'middle', fill: C.ink, size: 22, weight: 700 } ) );
		svg.appendChild( stext( cx, cy + 14, 'in tiers', { anchor: 'middle', baseline: 'middle', fill: C.ink3, size: 11 } ) );

		/* legend with label + count + % (identity carried by text, not colour alone) */
		var leg = el( 'ul', { class: 'yzrw-legend yzrw-legend-col' } );
		tiers.forEach( function ( t, i ) {
			leg.appendChild( el( 'li', {}, [
				el( 'span', { class: 'yzrw-swatch', style: 'background:' + colors[ i ] } ),
				el( 'span', { class: 'yzrw-legend-label', text: t.label } ),
				el( 'span', { class: 'yzrw-legend-val', text: fmt( t.count ) + ' · ' + ( t.count / total * 100 ).toFixed( 0 ) + '%' } )
			] ) );
		} );

		wrap.appendChild( svg ); wrap.appendChild( tip );
		box.appendChild( el( 'div', { class: 'yzrw-donut-row' }, [ wrap, leg ] ) );
		return box;
	}

	/* ---- shared bits ---- */

	function legend( series ) {
		var ul = el( 'ul', { class: 'yzrw-legend' } );
		series.forEach( function ( se ) {
			ul.appendChild( el( 'li', {}, [
				el( 'span', { class: 'yzrw-swatch', style: 'background:' + se.color } ),
				el( 'span', { text: se.label } )
			] ) );
		} );
		return ul;
	}
	function emptyNote() { return el( 'p', { class: 'description yzrw-empty', text: 'No data for this range yet.' } ); }

	/* ---- tables + CSV export (also the accessible "table view") ---- */

	function tableCard( title, report, head, body ) {
		var t = el( 'table', { class: 'widefat striped yzrw-table' } );
		t.appendChild( el( 'thead', {}, [ el( 'tr', {}, head.map( function ( h ) { return el( 'th', { text: h } ); } ) ) ] ) );
		var tb = el( 'tbody' );
		body.forEach( function ( r ) { tb.appendChild( el( 'tr', {}, r.map( function ( c ) { return el( 'td', { text: c } ); } ) ) ); } );
		t.appendChild( tb );
		var exp = el( 'button', { class: 'button', text: 'Export CSV' } );
		exp.addEventListener( 'click', function () { exportCsv( report ); } );
		return el( 'div', { class: 'yzrw-card' }, [
			el( 'div', { class: 'yzrw-card-head' }, [ el( 'h2', { text: title } ), exp ] ),
			t
		] );
	}

	function tablesSection() {
		var cu = data.customers, ca = data.campaigns, b = data.business, wrap = el( 'div' );

		wrap.appendChild( tableCard( 'Customers by tier', 'customers',
			[ 'Tier', 'Customers' ],
			( cu.tiers || [] ).map( function ( t ) { return [ t.label, fmt( t.count ) ]; } )
		) );

		wrap.appendChild( tableCard( 'Campaign performance', 'campaigns',
			[ 'Campaign', 'Participants', 'Submissions', 'Points', 'Engagement' ],
			( ca.best || [] ).map( function ( c ) { return [ c.title, fmt( c.participants ), fmt( c.submissions ), fmt( c.points_generated ), pct( c.engagement_rate ) ]; } )
		) );

		wrap.appendChild( tableCard( 'Business metrics', 'business',
			[ 'Metric', 'Value' ],
			[
				[ 'Referral revenue', money( b.referral_revenue ) ],
				[ 'Reward cost (value)', money( b.reward_cost.value ) ],
				[ 'Reward cost (points)', fmt( b.reward_cost.points ) ],
				[ 'Redemptions', fmt( b.reward_cost.redemptions ) ],
				[ 'Point liability (value)', money( b.point_liability.total_value ) ],
				[ 'Point liability (points)', fmt( b.point_liability.points_outstanding ) ],
				[ 'Customer retention', pct( b.retention_rate ) ],
				[ 'Customer lifetime value', money( b.clv ) ],
				[ 'Purchasing customers', fmt( b.purchasing_customers ) ]
			]
		) );
		return wrap;
	}

	function exportCsv( report ) {
		api( '/export?report=' + encodeURIComponent( report ) + '&range=' + encodeURIComponent( range ) ).then( function ( res ) {
			var blob = new Blob( [ res.csv ], { type: 'text/csv;charset=utf-8' } );
			var url  = URL.createObjectURL( blob );
			var a    = el( 'a', { href: url, download: res.filename } );
			document.body.appendChild( a ); a.click();
			setTimeout( function () { URL.revokeObjectURL( url ); a.remove(); }, 0 );
		} ).catch( function ( e ) { window.alert( e.message ); } );
	}

	/* ---- render / load ---- */

	function render() {
		clear( root );
		root.appendChild( toolbar() );
		root.appendChild( kpiSection() );
		root.appendChild( el( 'div', { class: 'yzrw-charts' }, [ lineChart(), el( 'div', { class: 'yzrw-charts-row' }, [ barChart(), donutChart() ] ) ] ) );
		root.appendChild( tablesSection() );
	}

	function load() {
		clear( root );
		root.appendChild( el( 'p', { class: 'yzrw-loading', text: 'Loading…' } ) );
		api( '/overview?range=' + encodeURIComponent( range ) ).then( function ( d ) {
			data = d; render();
		} ).catch( function ( e ) {
			clear( root );
			root.appendChild( el( 'div', { class: 'notice notice-error' }, [ el( 'p', { text: e.message } ) ] ) );
		} );
	}

	load();
} )();

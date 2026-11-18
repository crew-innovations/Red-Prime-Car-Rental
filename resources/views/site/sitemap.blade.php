{!! '<?xml version="1.0" encoding="UTF-8"?>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach ($urls as $u)
@foreach (['loc' => $u['loc'], 'ar' => $u['ar']] as $which => $loc)
  <url>
    <loc>{{ $loc }}</loc>
    <xhtml:link rel="alternate" hreflang="en" href="{{ $u['loc'] }}"/>
    <xhtml:link rel="alternate" hreflang="ar" href="{{ $u['ar'] }}"/>
    <changefreq>{{ $u['freq'] }}</changefreq>
    <priority>{{ $u['priority'] }}</priority>
  </url>
@endforeach
@endforeach
</urlset>

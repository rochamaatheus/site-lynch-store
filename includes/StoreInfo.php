<?php
/**
 * Lynch Store - dados da loja para a vitrine (rodape, Como comprar, JSON-LD).
 *
 * Tudo vem de site_settings (editavel em admin/configuracoes.php). Sem valor
 * cadastrado, a informacao simplesmente nao aparece: nada e inventado aqui.
 *
 * Horario em uma chave so (opening_hours), JSON com tres grupos e ate dois
 * intervalos cada:
 *   {"seg_sex":[["09:00","12:00"],["14:00","18:30"]],"sab":[["09:00","12:00"]],"dom":[]}
 */

declare(strict_types=1);

require_once __DIR__ . '/SettingsRepository.php';
require_once __DIR__ . '/functions.php';

const STORE_HOUR_GROUPS = [
    'seg_sex' => ['Segunda a sexta', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']],
    'sab'     => ['Sábado', ['Saturday']],
    'dom'     => ['Domingo', ['Sunday']],
];

/** Nome da loja (padrao "Lynch Store"). */
function store_name(): string
{
    return setting('store_name', 'Lynch Store');
}

/**
 * Horario normalizado: grupo => lista de [inicio, fim] validos.
 *
 * @return array<string,array<int,array{0:string,1:string}>>
 */
function store_hours(?string $json = null): array
{
    $raw = json_decode($json ?? SettingsRepository::raw('opening_hours'), true);
    $out = [];
    foreach (array_keys(STORE_HOUR_GROUPS) as $group) {
        $out[$group] = [];
        foreach ((is_array($raw) && is_array($raw[$group] ?? null)) ? $raw[$group] : [] as $pair) {
            if (is_array($pair) && count($pair) === 2 && store_valid_time($pair[0]) && store_valid_time($pair[1]) && $pair[0] < $pair[1]) {
                $out[$group][] = [(string) $pair[0], (string) $pair[1]];
            }
        }
    }
    return $out;
}

function store_valid_time(mixed $time): bool
{
    return is_string($time) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $time) === 1;
}

/** true quando ha pelo menos um intervalo cadastrado. */
function store_has_hours(): bool
{
    return array_filter(store_hours()) !== [];
}

/**
 * Linhas para mostrar: [['Segunda a sexta', '09:00 às 12:00 e 14:00 às 18:30'], ...].
 *
 * @return array<int,array{0:string,1:string}>
 */
function store_hours_lines(): array
{
    $hours = store_hours();
    if (array_filter($hours) === []) {
        return [];
    }
    $lines = [];
    foreach (STORE_HOUR_GROUPS as $group => [$label]) {
        $parts = array_map(static fn (array $p): string => $p[0] . ' às ' . $p[1], $hours[$group]);
        $lines[] = [$label, $parts === [] ? 'Fechado' : implode(' e ', $parts)];
    }
    return $lines;
}

/** Numero do WhatsApp como o aplicativo mostra: +55 47 99178-1461. */
function whatsapp_display(?string $digits = null): string
{
    $digits ??= whatsapp_number();
    $len = strlen($digits);
    if (str_starts_with($digits, '55') && ($len === 12 || $len === 13)) {
        $rest = substr($digits, 4);
        $half = strlen($rest) - 4;
        return '+55 ' . substr($digits, 2, 2) . ' ' . substr($rest, 0, $half) . '-' . substr($rest, $half);
    }
    return $digits === '' ? '' : '+' . $digits;
}

/** "@lynchstore" a partir do endereco do perfil. */
function instagram_handle(?string $url = null): string
{
    $url ??= setting('instagram_url');
    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
    $user = explode('/', $path)[0] ?? '';
    return preg_match('/^[A-Za-z0-9._]{1,30}$/D', $user) === 1 ? '@' . $user : '';
}

/**
 * Endereco em partes (so as preenchidas).
 *
 * @return array{street:string,district:string,city:string,state:string,zip:string}
 */
function store_address(): array
{
    return [
        'street'   => setting('address_street'),
        'district' => setting('address_district'),
        'city'     => setting('address_city'),
        'state'    => setting('address_state'),
        'zip'      => setting('address_zip'),
    ];
}

/** Duas linhas: "Avenida Júpiter, 146, Jardim Paraíso" e "Joinville - SC, CEP 89226-600". */
function store_address_lines(): array
{
    $a = store_address();
    $first = implode(', ', array_filter([$a['street'], $a['district']]));
    $cityState = implode(' - ', array_filter([$a['city'], $a['state']]));
    $second = implode(', ', array_filter([$cityState, $a['zip'] !== '' ? 'CEP ' . $a['zip'] : '']));
    return array_values(array_filter([$first, $second]));
}

/** Link de rota no mapa a partir do endereco (vazio sem endereco). */
function store_maps_url(): string
{
    $lines = store_address_lines();
    return $lines === [] ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(implode(', ', $lines));
}

/**
 * Campos de LocalBusiness para o JSON-LD da home (so o que estiver cadastrado).
 *
 * @return array<string,mixed>
 */
function store_jsonld(): array
{
    $a = store_address();
    $data = [];
    if (setting('store_slogan') !== '') {
        $data['slogan'] = setting('store_slogan');
    }
    if (whatsapp_number() !== '') {
        $data['telephone'] = whatsapp_display();
    }
    if (setting('instagram_url') !== '') {
        $data['sameAs'] = [setting('instagram_url')];
    }
    if (array_filter($a) !== []) {
        $data['address'] = array_filter([
            '@type'           => 'PostalAddress',
            'streetAddress'   => implode(', ', array_filter([$a['street'], $a['district']])),
            'addressLocality' => $a['city'],
            'addressRegion'   => $a['state'],
            'postalCode'      => $a['zip'],
            'addressCountry'  => 'BR',
        ], static fn ($v): bool => $v !== '');
    }
    $specs = [];
    foreach (store_hours() as $group => $pairs) {
        foreach ($pairs as [$opens, $closes]) {
            $specs[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => STORE_HOUR_GROUPS[$group][1], 'opens' => $opens, 'closes' => $closes];
        }
    }
    if ($specs !== []) {
        $data['openingHoursSpecification'] = $specs;
    }
    return $data;
}

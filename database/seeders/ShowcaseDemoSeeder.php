<?php

namespace Database\Seeders;

use App\Models\Showcase;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Seeds demo gallery entries: each is ONE op screenshot + TWO "crew having fun" photos, all generated as SVG. */
class ShowcaseDemoSeeder extends Seeder
{
    public function run(): void
    {
        // demo reset — clear only previously-seeded demos (their images live under showcase/demo-…), keep real entries
        foreach (Showcase::all() as $e) {
            if (str_starts_with($e->images[0] ?? '', 'showcase/demo-')) {
                foreach ($e->images as $p) {
                    Storage::disk('local')->delete($p);
                }
                $e->delete();
            }
        }

        $userIds = User::orderBy('id')->take(3)->pluck('id')->all();

        // [title, faction, spines, story, credit, taggedIds, [crewCaption1, crewCaption2]]
        $demos = [
            ['Brunswick Fan · 14 layers', 'ENL', 6,
                "Five agents, one Saturday morning. We keyed every spine the night before, ran the auto-field plan straight off toady, and threw the whole fan inside-out before the local RES even logged in. Fourteen layers over downtown — cleanest build we've done.",
                'Agent Greaves', array_slice($userIds, 0, 2), ['the squad, post-fan', 'breakfast tacos earned']],
            ['Golden Isles Multilayer', 'RES', 5,
                "Cross-water op across the marsh. The key locker saved us — we knew exactly who was short before anyone drove anywhere. Comms kept the boat crew and the bridge crew on the same page the whole time.",
                'RES Glynn', array_slice($userIds, 1, 1), ['boat crew + bridge crew', 'marsh sunrise']],
            ['Sapelo Sunrise', 'ENL', 4,
                "Sunrise build out on the island. Smaller op, but every gate code and the ferry times lived in toady, so nobody got stuck at a locked gate at 5am. Field went up right as the sun cleared the trees.",
                null, array_slice($userIds, 0, 1), ['island crew', 'first light, fields up']],
        ];

        foreach ($demos as $i => [$title, $faction, $spines, $story, $credit, $tags, $caps]) {
            $color = $faction === 'RES' ? '#38bdf8' : '#1cf0a0';
            $stat = '◢ '.(1 + 2 * ($spines - 1)).' fields · '.(3 * $spines).' links';
            $images = [
                $this->put($this->scene($color, $spines, ($i + 1) * 100, $title, $stat)), // the op screenshot
                $this->put($this->crewScene($color, ($i + 1) * 100 + 1, $caps[0])),        // crew shot 1
                $this->put($this->crewScene($color, ($i + 1) * 100 + 2, $caps[1])),        // crew shot 2
            ];
            Showcase::create([
                'title' => $title, 'story' => $story, 'credit' => $credit,
                'images' => $images, 'tagged_ids' => $tags, 'published' => true,
            ]);
        }

        $this->command?->info('Seeded '.count($demos).' showcase demos (1 screenshot + 2 crew shots each).');
    }

    private function put(string $svg): string
    {
        $path = 'showcase/demo-'.Str::random(32).'.svg';
        Storage::disk('local')->put($path, $svg);

        return $path;
    }

    /** A stylised 2-anchor fan-field "scanner screenshot". */
    private function scene(string $color, int $spines, int $seed, string $title, string $stat): string
    {
        mt_srand($seed);
        $a1 = [220, 240];
        $a2 = [980, 240];
        $sp = [];
        for ($i = 0; $i < $spines; $i++) {
            $t = $spines === 1 ? 0.5 : $i / ($spines - 1);
            $sp[] = [600 + mt_rand(-80, 80), 380 + (int) ($t * 430)];
        }

        $tri = fn ($p, $q, $r) => '<polygon points="'.$p[0].','.$p[1].' '.$q[0].','.$q[1].' '.$r[0].','.$r[1].
            '" fill="'.$color.'" fill-opacity="0.10" stroke="'.$color.'" stroke-opacity="0.30" stroke-width="1.5"/>';
        $line = fn ($p, $q) => '<line x1="'.$p[0].'" y1="'.$p[1].'" x2="'.$q[0].'" y2="'.$q[1].'" stroke="'.$color.'" stroke-opacity="0.5" stroke-width="2"/>';
        $dot = fn ($p, $r) => '<circle cx="'.$p[0].'" cy="'.$p[1].'" r="'.$r.'" fill="'.$color.'" fill-opacity="0.2"/>'.
            '<circle cx="'.$p[0].'" cy="'.$p[1].'" r="'.$r.'" fill="none" stroke="'.$color.'" stroke-width="2.5"/>';

        $fields = $links = $dots = '';
        $prev = null;
        foreach ($sp as $s) {
            $fields .= $prev ? $tri($a1, $prev, $s).$tri($a2, $prev, $s) : $tri($a1, $a2, $s);
            $links .= $line($s, $a1).$line($s, $a2).($prev ? $line($s, $prev) : '');
            $dots .= $dot($s, 9);
            $prev = $s;
        }
        $links .= $line($a1, $a2);
        $dots .= $dot($a1, 13).$dot($a2, 13);

        $grid = '';
        for ($x = 0; $x <= 1200; $x += 60) {
            $grid .= '<line x1="'.$x.'" y1="0" x2="'.$x.'" y2="900" stroke="#0e2019" stroke-width="1"/>';
        }
        for ($y = 0; $y <= 900; $y += 60) {
            $grid .= '<line x1="0" y1="'.$y.'" x2="1200" y2="'.$y.'" stroke="#0e2019" stroke-width="1"/>';
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900" font-family="ui-monospace,monospace">'
            .'<defs><radialGradient id="bg" cx="50%" cy="36%" r="82%"><stop offset="0%" stop-color="#0a1611"/><stop offset="100%" stop-color="#04070b"/></radialGradient></defs>'
            .'<rect width="1200" height="900" fill="url(#bg)"/>'.$grid.$fields.$links.$dots
            .'<text x="44" y="62" fill="'.$color.'" font-size="25" letter-spacing="7" opacity="0.9">▲ toady</text>'
            .'<text x="44" y="94" fill="'.$color.'" font-size="17" opacity="0.55">'.$this->esc($stat).'</text>'
            .'<rect x="0" y="792" width="1200" height="108" fill="#04070b" opacity="0.65"/>'
            .'<text x="44" y="858" fill="#e8fff5" font-size="38" font-weight="600">'.$this->esc($title).'</text>'
            .'</svg>';
    }

    /** A "crew having fun" silhouette photo — a row of agents against a sunset, some with arms up. */
    private function crewScene(string $color, int $seed, string $caption): string
    {
        mt_srand($seed);
        $ground = 720;
        $sky = '<defs><linearGradient id="sky" x1="0" y1="0" x2="0" y2="1">'
            .'<stop offset="0%" stop-color="#0a1622"/><stop offset="50%" stop-color="#19202f"/>'
            .'<stop offset="76%" stop-color="'.$color.'" stop-opacity="0.30"/>'
            .'<stop offset="100%" stop-color="#f6a268" stop-opacity="0.55"/></linearGradient></defs>';
        $o = '<rect width="1200" height="900" fill="url(#sky)"/>'
            .'<circle cx="600" cy="'.$ground.'" r="200" fill="#f7c98c" fill-opacity="0.22"/>'
            .'<polygon points="320,200 880,200 600,440" fill="'.$color.'" fill-opacity="0.06" stroke="'.$color.'" stroke-opacity="0.18" stroke-width="2"/>'
            .'<rect x="0" y="'.$ground.'" width="1200" height="'.(900 - $ground).'" fill="#04100a"/>';

        $n = mt_rand(4, 6);
        for ($i = 0; $i < $n; $i++) {
            $x = (int) round(170 + ($i + 0.5) * (860 / $n) + mt_rand(-18, 18));
            $o .= $this->person($x, $ground + 8, mt_rand(225, 285), mt_rand(0, 2) === 0);
        }

        $o .= '<text x="44" y="60" fill="'.$color.'" font-size="25" letter-spacing="7" opacity="0.85" font-family="ui-monospace,monospace">▲ toady</text>'
            .'<text x="44" y="866" fill="#e8fff5" font-size="30" font-weight="600" font-family="ui-monospace,monospace">'.$this->esc($caption).'</text>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900">'.$sky.$o.'</svg>';
    }

    /** One standing-person silhouette; $cheer raises the arms. */
    private function person(int $x, int $ground, int $h, bool $cheer): string
    {
        $f = '#05100b';
        $hr = (int) round($h * 0.11);
        $head = $ground - $h + $hr;
        $sh = $head + (int) round($hr * 1.8);
        $bw = (int) round($h * 0.26);
        $hip = $sh + (int) round($h * 0.40);
        $aw = max(6, (int) round($bw * 0.34));

        $o = '<circle cx="'.$x.'" cy="'.$head.'" r="'.$hr.'" fill="'.$f.'"/>'
            .'<rect x="'.($x - (int) round($bw / 2)).'" y="'.$sh.'" width="'.$bw.'" height="'.($hip - $sh).'" rx="'.(int) round($bw * 0.32).'" fill="'.$f.'"/>'
            .'<line x1="'.($x - (int) round($bw * 0.22)).'" y1="'.$hip.'" x2="'.($x - (int) round($bw * 0.22)).'" y2="'.$ground.'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>'
            .'<line x1="'.($x + (int) round($bw * 0.22)).'" y1="'.$hip.'" x2="'.($x + (int) round($bw * 0.22)).'" y2="'.$ground.'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>';

        if ($cheer) {
            $o .= '<line x1="'.($x - (int) round($bw * 0.4)).'" y1="'.($sh + (int) round($h * 0.04)).'" x2="'.($x - (int) round($bw * 0.9)).'" y2="'.($sh - (int) round($h * 0.18)).'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>'
                .'<line x1="'.($x + (int) round($bw * 0.4)).'" y1="'.($sh + (int) round($h * 0.04)).'" x2="'.($x + (int) round($bw * 0.9)).'" y2="'.($sh - (int) round($h * 0.18)).'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>';
        } else {
            $o .= '<line x1="'.($x - (int) round($bw * 0.4)).'" y1="'.($sh + (int) round($h * 0.04)).'" x2="'.($x - (int) round($bw * 0.7)).'" y2="'.($hip - (int) round($h * 0.04)).'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>'
                .'<line x1="'.($x + (int) round($bw * 0.4)).'" y1="'.($sh + (int) round($h * 0.04)).'" x2="'.($x + (int) round($bw * 0.7)).'" y2="'.($hip - (int) round($h * 0.04)).'" stroke="'.$f.'" stroke-width="'.$aw.'" stroke-linecap="round"/>';
        }

        return $o;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1);
    }
}

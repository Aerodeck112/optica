<?php

namespace Database\Seeders;

use App\Modules\Catalog\Models\Game;
use App\Modules\Catalog\Models\GameGenre;
use App\Modules\Cms\Models\Media;
use Illuminate\Database\Seeder;

/**
 * Catalogul de jocuri din materialele oficiale ale francizei. Toate jocurile pornesc „neconfirmate pentru Cluj”:
 * nu apar public până când operatorul confirmă disponibilitatea, vârsta, numărul de jucători și durata.
 * Valorile publicate de franciză (care diferă între surse) sunt păstrate doar ca referință internă.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $genres = [];
        foreach ([
            'actiune' => ['ro' => 'Acțiune', 'en' => 'Action'],
            'aventura' => ['ro' => 'Aventură', 'en' => 'Adventure'],
            'sf' => ['ro' => 'SF', 'en' => 'Sci-Fi'],
            'cooperare' => ['ro' => 'Cooperare', 'en' => 'Co-op'],
        ] as $slug => $name) {
            $genres[$slug] = GameGenre::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'sort' => count($genres)])->id;
        }

        $sourceSite = 'another-world.com (lista jocurilor, consultată 23.10.2026)';
        $sourceFranchise = 'franchise.another-world.com/games (consultat 23.10.2026)';

        foreach ($this->games() as $i => $g) {
            $cover = Media::query()->where('static_key', $g['cover'])->value('id');
            $gallery = Media::query()->whereIn('static_key', $g['gallery'])->pluck('id', 'static_key');
            $trailer = isset($g['trailer']) ? Media::query()->where('static_key', 'trailer-'.$g['trailer'])->value('id') : null;

            $game = Game::query()->where('slug->ro', $g['slug'])->first() ?? new Game;
            $game->fill([
                'slug' => ['ro' => $g['slug']],
                'title' => ['ro' => $g['title']],
                'tagline' => ['ro' => $g['tagline']],
                'description' => ['ro' => $g['description']],
                'genre_id' => $genres[$g['genre']],
                'audience' => $g['audience'],
                // necompletate intenționat: se confirmă pentru Cluj din panou
                'min_age' => null,
                'min_players' => null,
                'max_players' => null,
                'play_minutes' => null,
                'intensity' => null,
                'reference_data' => [
                    'note' => 'Valori publicate de franciză — NU se afișează public. Confirmați-le pentru arena din Cluj.',
                    'sources' => array_filter([
                        isset($g['ref_site']) ? ['source' => $sourceSite] + $g['ref_site'] : null,
                        isset($g['ref_franchise']) ? ['source' => $sourceFranchise] + $g['ref_franchise'] : null,
                        isset($g['ref_doc']) ? ['source' => 'Descriere oficială (Drive „Game materials”)'] + $g['ref_doc'] : null,
                    ]),
                    'suggested_intensity' => $g['intensity'],
                ],
                'cluj_status' => 'unconfirmed',
                'cover_id' => $cover,
                'gallery_ids' => array_values(array_filter(array_map(fn ($key) => $gallery[$key] ?? null, $g['gallery']))),
                'trailer_id' => $trailer,
                'published_locales' => ['ro'],
                'sort' => $i * 10,
            ])->save();
        }
    }

    private function games(): array
    {
        $shots = fn (string $slug, int $n) => array_map(fn ($i) => "game-{$slug}-{$i}", range(1, $n));

        return [
            [
                'slug' => 'kernel-bunker', 'title' => 'Kernel: Bunker', 'genre' => 'actiune', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Shooter tactic într-un buncăr în care fizica nu mai ascultă de reguli.',
                'description' => "Două echipe se înfruntă pe teritoriul unui buncăr abandonat, unde se petrec lucruri ciudate: un misterios soare negru schimbă legile fizicii.\n\nFiecare detaliu al decorului este lucrat atent, pentru o imersiune completă: atmosfera trecutului, arme realiste și fenomene paranormale. Comunicarea și strategia de echipă fac diferența.",
                'cover' => 'game-kernel-bunker-1', 'gallery' => $shots('kernel-bunker', 3), 'trailer' => 'kernel-bunker',
                'ref_site' => ['players' => '4–20', 'age' => '12+', 'minutes' => 60],
                'ref_franchise' => ['players' => 'neclar în pagină', 'age' => 'neclar în pagină', 'minutes' => 60],
            ],
            [
                'slug' => 'kernel-raven', 'title' => 'Kernel: Raven', 'genre' => 'actiune', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Luptă la înălțime, pe șantierul unui zgârie-nori.',
                'description' => "Echipele adverse se întâlnesc pe șantierul zgârie-norului corporației Another World. O hartă vastă, cu multe adăposturi și opțiuni tactice, pentru cei care vor să-și testeze strategia.\n\nGrafica realistă și nivelul ridicat de detaliu fac ca fiecare rundă să pară o misiune adevărată.",
                'cover' => 'game-kernel-raven-1', 'gallery' => $shots('kernel-raven', 3),
                'ref_site' => ['players' => '4–20', 'age' => '12+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '12+', 'minutes' => 60],
            ],
            [
                'slug' => 'kernel-survival-protocol', 'title' => 'Kernel: Survival Protocol', 'genre' => 'actiune', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Misiune de supraviețuire, cu inamici înfricoșători și nervi întinși la maximum.',
                'description' => "Echipa are de îndeplinit obiective dificile într-un loc în care fiecare colț poate ascunde un pericol. Atmosfera tensionată și inamicii înfricoșători vă țin în alertă până la final.\n\nRecomandat celor care caută o experiență intensă.",
                'cover' => 'game-kernel-survival-protocol-1', 'gallery' => $shots('kernel-survival-protocol', 2),
                'ref_site' => ['players' => '4–20', 'age' => '12+', 'minutes' => 60],
            ],
            [
                'slug' => 'shmooter-cubes', 'title' => 'Shmooter: Cubes', 'genre' => 'actiune', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Bătălii colorate într-o lume construită din cuburi.',
                'description' => "O lume virtuală construită din cuburi: munți înzăpeziți, păduri, case și dirijabile. Echipele se întrec în bătălii pline de energie, cu grafică prietenoasă.\n\nPotrivit pentru grupuri mixte, în care fiecare găsește un rol.",
                'cover' => 'game-shmooter-cubes-1', 'gallery' => $shots('shmooter-cubes', 3), 'trailer' => 'shmooter',
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'shmooter-forts', 'title' => 'Shmooter: Forts', 'genre' => 'actiune', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Cucerește baza adversă și apără-ți fortăreața.',
                'description' => "Intră într-o lume virtuală plină de culoare și bătălii epice în echipă. Cucerește baza, distruge clădirile, mută-te între platforme și folosește autobuzul ca adăpost.\n\nPrecizia și reflexele contează, dar și felul în care vă coordonați.",
                'cover' => 'game-shmooter-forts-1', 'gallery' => $shots('shmooter-forts', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'shmooter-island-assault', 'title' => 'Shmooter: Island Assault', 'genre' => 'actiune', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Găsește echipamentul ascuns pe insulă și apără-ți baza.',
                'description' => "Armele și protecțiile sunt ascunse pe toată insula. Echipa trebuie să le găsească repede și să apere insula de invadatori.\n\nO aventură de acțiune luminoasă, potrivită și pentru jucătorii mai tineri.",
                'cover' => 'game-shmooter-island-assault-1', 'gallery' => $shots('shmooter-island-assault', 2),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'shmooter-skyscrapers', 'title' => 'Shmooter: Skyscrapers', 'genre' => 'actiune', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Duel între zgârie-nori: fiecare pentru el sau echipă contra echipă.',
                'description' => "Luptă împotriva prietenilor în modul „fiecare pentru el” sau „echipă contra echipă”. Strânge bonusuri ca să devii mai puternic, ia-ți adversarii prin surprindere și antrenează-ți precizia și reflexele.\n\nGrafica de tip desen animat face jocul accesibil și distractiv.",
                'cover' => 'game-shmooter-skyscrapers-1', 'gallery' => $shots('shmooter-skyscrapers', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
                'ref_doc' => ['players' => '2–10', 'age' => '12+', 'note' => 'Descrierea oficială indică 12+, spre deosebire de site (6+). Confirmați.'],
            ],
            [
                'slug' => 'shmooter-space-battle', 'title' => 'Shmooter: Space Battle', 'genre' => 'sf', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Apărați stația spațială de invadatorii extratereștri.',
                'description' => "Spațiu nesfârșit, stele reci și îndepărtate — și o stație spațială atacată de invadatori extratereștri. Echipa trebuie să o apere, cot la cot.\n\nO bătălie SF plină de culoare și energie.",
                'cover' => 'game-shmooter-space-battle-1', 'gallery' => $shots('shmooter-space-battle', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'shmooter-space-battle-2', 'title' => 'Shmooter: Space Battle 2', 'genre' => 'sf', 'audience' => 'all', 'intensity' => 2,
                'tagline' => 'Arme noi, teleportoare și lupte pe mai multe niveluri.',
                'description' => "Bătăliile continuă pe stația spațială. De data aceasta ai o armă nouă în arsenal, iar teleportoarele te duc pe alte etaje ale stației.\n\nLuptă fiecare pentru el sau echipă contra echipă — și încearcă să nu zbori în spațiul cosmic.",
                'cover' => 'game-shmooter-space-battle-2-1', 'gallery' => $shots('shmooter-space-battle-2', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => 'neclar în pagină', 'minutes' => 60],
            ],
            [
                'slug' => 'ghost-tower-conacul-fantomelor', 'title' => 'Ghost Tower: Ghost Mansion', 'genre' => 'aventura', 'audience' => 'kids', 'intensity' => 1,
                'tagline' => 'Vânători de fantome într-un conac plin de mistere.',
                'description' => "Un grup de vânători de fantome ajunge într-un conac abandonat. Creaturi de pe altă lume au invadat casa, iar echipa trebuie să o elibereze folosind o armă magică specială.\n\nAtenție: fantomele sunt viclene! O aventură gândită pentru copii.",
                'cover' => 'game-ghost-tower-ghost-mansion-1', 'gallery' => $shots('ghost-tower-ghost-mansion', 2), 'trailer' => 'ghost-tower',
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
                'ref_doc' => ['players' => '2–10', 'age' => '6–12 ani'],
            ],
            [
                'slug' => 'ghost-tower-dance', 'title' => 'Ghost Tower: Dance', 'genre' => 'aventura', 'audience' => 'kids', 'intensity' => 1,
                'tagline' => 'Petrecere de dans cu fantome prietenoase.',
                'description' => "Într-un conac abandonat plin de fantome adorabile, petrecerea începe! Alegi muzica, repeți mișcările personajelor și concurezi cu prietenii pentru cele mai multe puncte.\n\nFantomele amuzante devin galeria ta.",
                'cover' => 'game-ghost-tower-dance-1', 'gallery' => $shots('ghost-tower-dance', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'ghost-tower-fishing', 'title' => 'Ghost Tower: Fishing', 'genre' => 'aventura', 'audience' => 'kids', 'intensity' => 1,
                'tagline' => 'Pescuit cu fantome, pentru cei mici.',
                'description' => "Un joc pentru copii în care jucătorii încearcă să prindă cât mai mulți pești colorați. Competiție prietenoasă, fantome care te ajută, comenzi simple și grafică veselă.\n\nPerfect pentru tinerii aventurieri.",
                'cover' => 'game-ghost-tower-fishing-1', 'gallery' => $shots('ghost-tower-fishing', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'mysterious-tower-miners', 'title' => 'Mysterious Tower: Miners', 'genre' => 'aventura', 'audience' => 'kids', 'intensity' => 1,
                'tagline' => 'Devino miner și adună cristale cu echipa ta.',
                'description' => "Alegeți-vă avatarurile, luați târnăcoapele și porniți în mină. Adunați cristale, strângeți puncte și deveniți legendele minei.\n\nO aventură de echipă, cu reguli simple.",
                'cover' => 'trailer-ghost-tower-miners-poster', 'gallery' => [], 'trailer' => 'ghost-tower-miners',
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60],
                'ref_doc' => ['players' => '2–10'],
            ],
            [
                'slug' => 'safe-night', 'title' => 'Safe Night', 'genre' => 'actiune', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Rezistați împreună până la evacuare.',
                'description' => "După un dezastru radioactiv, supraviețuitorii s-au transformat în mutanți. Echipa trebuie să reziste până la sosirea evacuării.\n\nTensiune, cooperare și decizii rapide.",
                'cover' => 'game-safe-night-1', 'gallery' => $shots('safe-night', 2),
                'ref_site' => ['players' => '2–20', 'age' => '12+', 'minutes' => 60],
                'ref_franchise' => ['players' => '2–4', 'age' => '12+', 'minutes' => 60],
            ],
            [
                'slug' => 'starbase-expedition', 'title' => 'Starbase: Expedition', 'genre' => 'sf', 'audience' => 'teens_adults', 'intensity' => 2,
                'tagline' => 'Misiune de echipă pe o stație spațială îndepărtată.',
                'description' => "Coloniștii ajung pe o stație spațială de pe planeta Alekta, la marginea spațiului explorat. Ce găsesc acolo este începutul unui coșmar.\n\nO misiune SF în care echipa trebuie să lucreze împreună pentru a rezolva misterul stației.",
                'cover' => 'trailer-starbase-expedition-poster', 'gallery' => [], 'trailer' => 'starbase-expedition',
                'ref_site' => ['players' => '2–20', 'age' => '12+', 'minutes' => 60],
                'ref_franchise' => ['players' => '2–4', 'age' => '12+', 'minutes' => 60],
            ],
            [
                'slug' => 'starbase-survival', 'title' => 'Starbase: Survival', 'genre' => 'sf', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Blocați pe o platformă, înconjurați de forme de viață necunoscute.',
                'description' => "Echipa rămâne blocată pe o platformă înconjurată de forme de viață periculoase, care au evoluat în moduri neașteptate. Supraviețuirea depinde de cât de bine vă coordonați.",
                'cover' => 'game-starbase-survival-1', 'gallery' => $shots('starbase-survival', 3),
                'ref_site' => ['players' => '2–20', 'age' => '12+', 'minutes' => 60],
                'ref_franchise' => ['players' => '1–4', 'age' => '12+', 'minutes' => 60],
            ],
            [
                'slug' => 'taverna', 'title' => 'Tavern', 'genre' => 'cooperare', 'audience' => 'all', 'intensity' => 1,
                'tagline' => 'Preluați o tavernă fantastică și serviți oaspeți neobișnuiți.',
                'description' => "Elfi, vrăjitori, cavaleri și alți oaspeți fantastici așteaptă să fie serviți. Echipa preia taverna și trebuie să lucreze sincron: pregătește comenzile, servește și ține ritmul.\n\nUn joc de cooperare, fără arme, potrivit pentru grupuri mixte.",
                'cover' => 'game-tavern-1', 'gallery' => $shots('tavern', 3),
                'ref_site' => ['players' => '2–20', 'age' => '6+', 'minutes' => 60, 'genre' => 'PVE'],
                'ref_franchise' => ['players' => '4–20', 'age' => '6+', 'minutes' => 60],
            ],
            [
                'slug' => 'colony-code-red', 'title' => 'Colony: Code Red', 'genre' => 'sf', 'audience' => 'teens_adults', 'intensity' => 3,
                'tagline' => 'Anul 2508. Echipajul navei Vega ajunge pe planeta Alekta.',
                'description' => "Omenirea colonizează spațiul. Echipajele dorm în capsule criogenice, iar inteligența artificială pilotează navele spre colonii îndepărtate.\n\nVoi sunteți echipajul navei coloniale Vega, trimis să înlocuiască coloniștii de pe planeta Alekta. Ce ar putea să meargă prost?",
                'cover' => 'game-colony-code-red-1', 'gallery' => $shots('colony-code-red', 3), 'trailer' => 'colony-code-red',
                'ref_doc' => ['players' => '2–10', 'minutes' => 30, 'note' => 'Jocul nu apare în lista publică a francizei consultată; confirmați disponibilitatea.'],
            ],
        ];
    }
}

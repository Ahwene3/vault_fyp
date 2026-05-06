<?php
/**
 * Text similarity helpers for project duplicate detection.
 * Uses Jaccard similarity on word token sets, weighted by field importance.
 */

function sim_tokens(string $text): array {
    static $stop_index = null;
    if ($stop_index === null) {
        $stop_index = array_flip([
            'a','an','the','and','or','but','in','on','at','to','for','of','with','by',
            'from','is','are','was','were','be','been','have','has','had','do','does',
            'did','will','would','could','should','may','might','can','this','that',
            'these','those','it','its','not','as','if','then','than','so','into','about',
            'using','based','approach','system','study','analysis','design','development',
        ]);
    }
    $text  = strtolower(preg_replace('/[^a-z0-9\s]/i', ' ', $text));
    $words = preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    return array_values(array_unique(
        array_filter($words, fn($w) => strlen($w) > 2 && !isset($stop_index[$w]))
    ));
}

function sim_jaccard(array $a, array $b): float {
    if (!$a || !$b) return 0.0;
    $inter = count(array_intersect($a, $b));
    $union = count(array_unique(array_merge($a, $b)));
    return $union ? $inter / $union : 0.0;
}

/**
 * Compare title / keywords / abstract against all live projects.
 * Returns up to 8 matches sorted by score desc.
 * Each entry: [project_id, title, score (0-100), level (high|moderate|low)]
 */
function find_similar_projects(
    PDO $pdo,
    string $title,
    string $keywords = '',
    string $abstract  = ''
): array {
    $tT = sim_tokens($title);
    $tK = sim_tokens($keywords);
    $tA = sim_tokens($abstract);

    $stmt = $pdo->query(
        "SELECT id, title, keywords, description FROM projects
         WHERE status NOT IN ('draft','rejected') LIMIT 2000"
    );

    $results = [];
    foreach ($stmt->fetchAll() as $p) {
        $sT  = sim_jaccard($tT, sim_tokens($p['title']));
        $num = $sT * 0.5;
        $den = 0.5;

        if ($tK && $p['keywords']) {
            $num += sim_jaccard($tK, sim_tokens((string) $p['keywords'])) * 0.3;
            $den += 0.3;
        }
        if ($tA && $p['description']) {
            $num += sim_jaccard($tA, sim_tokens((string) $p['description'])) * 0.2;
            $den += 0.2;
        }

        $score = round(($num / $den) * 100, 1);
        if ($score >= 8.0) {
            $results[] = [
                'project_id' => (int) $p['id'],
                'title'      => $p['title'],
                'score'      => $score,
                'level'      => $score >= 60 ? 'high' : ($score >= 30 ? 'moderate' : 'low'),
            ];
        }
    }
    usort($results, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($results, 0, 8);
}

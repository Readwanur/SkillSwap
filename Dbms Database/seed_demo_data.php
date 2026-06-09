<?php
/**
 * ============================================================
 * SEED DATA GENERATOR: 50 Random Profiles + Sessions + Transactions
 * ============================================================
 * Populates the database with realistic test data to exercise all 6 features:
 *   1. Market Trends (needs skills_offered, skills_requested, sessions)
 *   2. Smart Matches (needs skills_offered/requested + availability)
 *   3. Collaborative Filtering (needs completed sessions as requester)
 *   4. Leaderboards (needs completed sessions as provider with ratings)
 *   5. Fraud Detection (needs transactions between users)
 *   6. Surge Pricing (needs recent sessions for velocity calculation)
 */

require_once __DIR__ . '/../config/db.php';

// Prevent accidental re-run
$existing = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE email LIKE '%@skillswap-demo.com'")->fetch_assoc()['cnt'];
if ($existing >= 50) {
    echo "<h3>⚠️ Seed data already exists ($existing demo users found). Aborting to prevent duplicates.</h3>";
    echo "<p>If you want to re-seed, manually delete users with emails ending in @skillswap-demo.com first.</p>";
    exit;
}

echo "<h2>🌱 Seeding 50 Random Profiles + Sessions + Transactions...</h2>";
echo "<style>body{font-family:'Segoe UI',sans-serif;max-width:800px;margin:20px auto;line-height:1.6;}</style>";

// --- Config ---
$NUM_USERS = 50;
$password_hash = password_hash('demo123', PASSWORD_DEFAULT);

$first_names = ['Aarav','Aditi','Alice','Amir','Anna','Arjun','Bob','Carlos','Chen','Clara',
    'Devi','Diana','Elena','Ethan','Fatima','Feng','Grace','Hassan','Isla','James',
    'Julia','Kai','Kira','Leo','Lina','Marco','Maya','Noah','Nora','Omar',
    'Priya','Qasim','Rachel','Ravi','Sakura','Sam','Sara','Tao','Uma','Victor',
    'Wendy','Xavier','Yara','Zain','Zoe','Tariq','Luna','Ivan','Hana','Diego'];

$last_names = ['Ahmed','Anderson','Becker','Chen','Das','Evans','Flores','Garcia','Honda','Islam',
    'Jones','Khan','Lee','Martinez','Nakamura','Okafor','Patel','Qin','Rahman','Silva',
    'Torres','Ueda','Vargas','Wang','Xavier','Yang','Zhang','Ali','Brown','Clark',
    'Diaz','Edwards','Foster','Green','Hill','Ito','Johnson','Kim','Lopez','Moore',
    'Nguyen','Ortiz','Park','Quinn','Rivera','Smith','Tanaka','Usman','Vega','White'];

$locations = ['New York, USA','London, UK','Tokyo, Japan','Berlin, Germany','Paris, France',
    'Sydney, Australia','Toronto, Canada','Mumbai, India','São Paulo, Brazil','Dubai, UAE',
    'Singapore','Seoul, South Korea','Amsterdam, Netherlands','Stockholm, Sweden','Dhaka, Bangladesh',
    'Bangkok, Thailand','Istanbul, Turkey','Mexico City, Mexico','Cairo, Egypt','Nairobi, Kenya'];

$bios = [
    'Passionate about teaching and learning new things every day.',
    'Full-stack developer who loves sharing knowledge with the community.',
    'Creative designer with a knack for minimalist interfaces.',
    'Language enthusiast who speaks 3 languages fluently.',
    'Music teacher with 10 years of experience in classical piano.',
    'Data scientist exploring the intersection of AI and education.',
    'Fitness trainer specializing in home workouts and nutrition.',
    'Amateur photographer who loves capturing urban landscapes.',
    'Marketing specialist with expertise in digital growth strategies.',
    'College student eager to learn everything from cooking to coding.',
];

$days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

// Get existing skills
$skill_rows = [];
$res = $conn->query("SELECT skill_id FROM skills ORDER BY skill_id");
while ($r = $res->fetch_assoc()) $skill_rows[] = $r['skill_id'];
$num_skills = count($skill_rows);

if ($num_skills == 0) {
    echo "<p style='color:red;'>❌ No skills found in the database. Please run the main database.sql first.</p>";
    exit;
}

echo "<p>Found $num_skills existing skills.</p>";

// --- STEP 1: Create 50 users ---
echo "<h3>Step 1: Creating $NUM_USERS users...</h3>";
$user_ids = [];
$conn->begin_transaction();
try {
    for ($i = 0; $i < $NUM_USERS; $i++) {
        $name = $first_names[$i] . ' ' . $last_names[$i];
        $email = strtolower($first_names[$i]) . '.' . strtolower($last_names[$i]) . '@skillswap-demo.com';
        $loc = $locations[$i % count($locations)];
        $bio = $bios[$i % count($bios)];
        $reliability = round(3.5 + mt_rand(0, 150) / 100, 2);
        $created = date('Y-m-d H:i:s', strtotime('-' . mt_rand(30, 365) . ' days'));

        $stmt = $conn->prepare("INSERT INTO users (name, email, password_hash, location, bio, reliability_score, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)");
        $stmt->bind_param("sssssds", $name, $email, $password_hash, $loc, $bio, $reliability, $created);
        $stmt->execute();
        $uid = $conn->insert_id;
        $user_ids[] = $uid;
        $stmt->close();

        // Create wallet (20-200 TC random balance)
        $balance = round(20 + mt_rand(0, 18000) / 100, 2);
        $conn->query("INSERT INTO wallet (user_id, balance) VALUES ($uid, $balance)");

        // Create reputation
        $comp_sessions = mt_rand(0, 25);
        $cancel_sessions = mt_rand(0, 3);
        $score = round(max(1, min(5, $reliability - ($cancel_sessions * 0.2))), 2);
        $mentor = 'Novice';
        if ($comp_sessions >= 20) $mentor = 'Master';
        elseif ($comp_sessions >= 10) $mentor = 'Expert';
        elseif ($comp_sessions >= 5) $mentor = 'Professional';
        $conn->query("INSERT INTO reputation (user_id, current_score, completed_sessions, cancelled_sessions, mentor_level) VALUES ($uid, $score, $comp_sessions, $cancel_sessions, '$mentor')");
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Created $NUM_USERS users with wallets and reputation.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error creating users: " . $e->getMessage() . "</p>";
    exit;
}

// --- STEP 2: Assign skills offered & requested ---
echo "<h3>Step 2: Assigning skills (offered & requested)...</h3>";
$conn->begin_transaction();
try {
    foreach ($user_ids as $uid) {
        // Each user offers 2-5 random skills
        $num_offered = mt_rand(2, 5);
        $offered_skills = array_rand(array_flip($skill_rows), min($num_offered, $num_skills));
        if (!is_array($offered_skills)) $offered_skills = [$offered_skills];
        foreach ($offered_skills as $sid) {
            $conn->query("INSERT IGNORE INTO user_skills_offered (user_id, skill_id) VALUES ($uid, $sid)");
        }

        // Each user requests 2-4 random skills (different from offered)
        $remaining = array_diff($skill_rows, $offered_skills);
        if (count($remaining) > 0) {
            $num_requested = min(mt_rand(2, 4), count($remaining));
            $requested_skills = array_rand(array_flip($remaining), $num_requested);
            if (!is_array($requested_skills)) $requested_skills = [$requested_skills];
            foreach ($requested_skills as $sid) {
                $conn->query("INSERT IGNORE INTO user_skills_requested (user_id, skill_id) VALUES ($uid, $sid)");
            }
        }
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Skills assigned to all users.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error assigning skills: " . $e->getMessage() . "</p>";
}

// --- STEP 3: Create availability schedules ---
echo "<h3>Step 3: Creating availability schedules...</h3>";
$conn->begin_transaction();
try {
    foreach ($user_ids as $uid) {
        // Each user has 2-4 availability slots
        $num_slots = mt_rand(2, 4);
        $used_days = [];
        for ($s = 0; $s < $num_slots; $s++) {
            $day = $days[mt_rand(0, 6)];
            if (in_array($day, $used_days)) continue;
            $used_days[] = $day;
            $start_h = mt_rand(8, 18);
            $end_h = min($start_h + mt_rand(2, 4), 22);
            $start_time = sprintf('%02d:00:00', $start_h);
            $end_time = sprintf('%02d:00:00', $end_h);
            $conn->query("INSERT INTO user_availability (user_id, day_of_week, start_time, end_time) VALUES ($uid, '$day', '$start_time', '$end_time')");
        }
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Availability schedules created.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error creating availability: " . $e->getMessage() . "</p>";
}

// --- STEP 4: Create exchange sessions ---
echo "<h3>Step 4: Creating exchange sessions (completed, scheduled, cancelled)...</h3>";
$session_ids = [];
$conn->begin_transaction();
try {
    $total_sessions = 0;
    // Create completed sessions (150+)
    for ($i = 0; $i < 180; $i++) {
        $requester = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        $provider = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        if ($requester == $provider) continue;

        $skill = $skill_rows[mt_rand(0, $num_skills - 1)];
        $duration = [30, 60, 60, 90, 60, 120][mt_rand(0, 5)];
        $tc = round(($duration / 60) * 10, 2);
        $rating = [3, 4, 4, 5, 5, 5, 5, 4, 5, 3][mt_rand(0, 9)]; // skewed towards 4-5
        $days_ago = mt_rand(1, 180);
        $scheduled = date('Y-m-d H:i:s', strtotime("-$days_ago days"));
        $completed = date('Y-m-d H:i:s', strtotime("-$days_ago days +$duration minutes"));
        $bonus = 1.00;
        $otp = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, completion_time, session_duration, time_credit_transfer, rating, feedback_given, bonus_multiplier, completion_otp) VALUES (?, ?, ?, 'completed', ?, ?, ?, ?, ?, TRUE, ?, ?)");
        $stmt->bind_param("iiissidids", $requester, $provider, $skill, $scheduled, $completed, $duration, $tc, $rating, $bonus, $otp);
        $stmt->execute();
        $session_ids[] = $conn->insert_id;
        $stmt->close();
        $total_sessions++;
    }

    // Create scheduled (upcoming) sessions (20)
    for ($i = 0; $i < 20; $i++) {
        $requester = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        $provider = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        if ($requester == $provider) continue;

        $skill = $skill_rows[mt_rand(0, $num_skills - 1)];
        $duration = [30, 60, 90][mt_rand(0, 2)];
        $tc = round(($duration / 60) * 10, 2);
        $days_future = mt_rand(1, 30);
        $scheduled = date('Y-m-d H:i:s', strtotime("+$days_future days"));
        $otp = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer, bonus_multiplier, completion_otp) VALUES (?, ?, ?, 'scheduled', ?, ?, ?, 1.00, ?)");
        $stmt->bind_param("iiisids", $requester, $provider, $skill, $scheduled, $duration, $tc, $otp);
        $stmt->execute();
        $session_ids[] = $conn->insert_id;
        $stmt->close();
        $total_sessions++;
    }

    // Create cancelled sessions (10)
    for ($i = 0; $i < 10; $i++) {
        $requester = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        $provider = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        if ($requester == $provider) continue;

        $skill = $skill_rows[mt_rand(0, $num_skills - 1)];
        $duration = 60;
        $tc = 10.00;
        $days_ago = mt_rand(5, 90);
        $scheduled = date('Y-m-d H:i:s', strtotime("-$days_ago days"));
        $otp = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);

        $stmt = $conn->prepare("INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, session_duration, time_credit_transfer, bonus_multiplier, completion_otp) VALUES (?, ?, ?, 'cancelled', ?, ?, ?, 1.00, ?)");
        $stmt->bind_param("iiisids", $requester, $provider, $skill, $scheduled, $duration, $tc, $otp);
        $stmt->execute();
        $stmt->close();
        $total_sessions++;
    }

    $conn->commit();
    echo "<p style='color:green;'>✅ Created $total_sessions exchange sessions.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error creating sessions: " . $e->getMessage() . "</p>";
}

// --- STEP 5: Create transactions for completed sessions ---
echo "<h3>Step 5: Creating transactions for completed sessions...</h3>";
$conn->begin_transaction();
try {
    $completed_sessions = $conn->query("
        SELECT session_id, requester_id, provider_id, time_credit_transfer, completion_time
        FROM exchange_sessions 
        WHERE status = 'completed' 
        AND session_id IN (" . implode(',', $session_ids) . ")
    ");
    $txn_count = 0;
    while ($sess = $completed_sessions->fetch_assoc()) {
        $conn->query("INSERT INTO transactions (session_id, from_user_id, to_user_id, type, base_amount, final_amount, note, timestamp)
            VALUES ({$sess['session_id']}, {$sess['requester_id']}, {$sess['provider_id']}, 'credit_transfer', 
            {$sess['time_credit_transfer']}, {$sess['time_credit_transfer']}, 
            'Time credit transfer for completed session', '{$sess['completion_time']}')");
        $txn_count++;
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Created $txn_count transactions.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error creating transactions: " . $e->getMessage() . "</p>";
}

// --- STEP 6: Create some "surge-worthy" providers (many recent sessions) ---
echo "<h3>Step 6: Creating surge pricing test data...</h3>";
$conn->begin_transaction();
try {
    // Pick 3 providers and give them 8+ recent sessions to trigger surge
    $surge_providers = array_slice($user_ids, 0, 3);
    $surge_count = 0;
    foreach ($surge_providers as $pid) {
        for ($j = 0; $j < mt_rand(6, 10); $j++) {
            $requester = $user_ids[mt_rand(10, $NUM_USERS - 1)];
            if ($requester == $pid) continue;
            $skill = $skill_rows[mt_rand(0, $num_skills - 1)];
            $days_ago = mt_rand(0, 5);
            $scheduled = date('Y-m-d H:i:s', strtotime("-$days_ago days"));
            $completed = date('Y-m-d H:i:s', strtotime("-$days_ago days +60 minutes"));
            $otp = str_pad(mt_rand(0, 9999), 4, '0', STR_PAD_LEFT);
            $conn->query("INSERT INTO exchange_sessions (requester_id, provider_id, skill_id, status, scheduled_time, completion_time, session_duration, time_credit_transfer, rating, feedback_given, bonus_multiplier, completion_otp) VALUES ($requester, $pid, $skill, 'completed', '$scheduled', '$completed', 60, 10.00, 5, TRUE, 1.00, '$otp')");
            $surge_count++;
        }
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Created $surge_count recent sessions for 3 surge providers.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}

// --- STEP 7: Create community tasks ---
echo "<h3>Step 7: Creating community tasks...</h3>";
$task_types = ['Bug Report', 'Tutorial Writing', 'Skill Review', 'Content Moderation', 'Translation', 'Mentoring Session'];
$conn->begin_transaction();
try {
    $task_count = 0;
    for ($i = 0; $i < 30; $i++) {
        $uid = $user_ids[mt_rand(0, $NUM_USERS - 1)];
        $type = $task_types[mt_rand(0, count($task_types) - 1)];
        $desc = "Community task: $type contribution #" . ($i + 1);
        $loc = $locations[mt_rand(0, count($locations) - 1)];
        $reward = [3.00, 5.00, 5.00, 8.00, 10.00][mt_rand(0, 4)];
        $status = ['pending', 'completed', 'completed', 'completed', 'in-progress'][mt_rand(0, 4)];
        $assigned = date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 90) . ' days'));
        $completed_at = ($status === 'completed') ? date('Y-m-d H:i:s', strtotime($assigned . ' +' . mt_rand(1, 5) . ' days')) : 'NULL';
        
        if ($completed_at === 'NULL') {
            $conn->query("INSERT INTO community_task (user_id, task_type, description, location, credit_reward, status, assigned_at) VALUES ($uid, '$type', '$desc', '$loc', $reward, '$status', '$assigned')");
        } else {
            $conn->query("INSERT INTO community_task (user_id, task_type, description, location, credit_reward, status, assigned_at, completed_at) VALUES ($uid, '$type', '$desc', '$loc', $reward, '$status', '$assigned', '$completed_at')");
        }
        $task_count++;
    }
    $conn->commit();
    echo "<p style='color:green;'>✅ Created $task_count community tasks.</p>";
} catch (Exception $e) {
    $conn->rollback();
    echo "<p style='color:red;'>❌ Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<h2 style='color:green;'>🎉 Seeding Complete!</h2>";
echo "<p><strong>Summary:</strong></p>";
echo "<ul>";
echo "<li>$NUM_USERS new users with wallets, reputation & availability</li>";
echo "<li>Skills offered & requested assigned per user</li>";
echo "<li>~210 exchange sessions (completed, scheduled, cancelled)</li>";
echo "<li>Transactions for all completed sessions</li>";
echo "<li>3 surge-worthy providers with 6-10 recent sessions each</li>";
echo "<li>30 community tasks</li>";
echo "</ul>";
echo "<p>🔑 <strong>All demo accounts use password:</strong> <code>demo123</code></p>";
echo "<p>📧 <strong>Email format:</strong> <code>firstname.lastname@skillswap-demo.com</code></p>";
echo "<p><a href='../pages/dashboard.php'>→ Go to Dashboard</a></p>";
?>

<?php
// زيادة وقت التنفيذ لعمليات الاستيراد الكبيرة
set_time_limit(600);

require_once __DIR__ . '/functions.php';
$db = initDb();
$message = '';

// ==========================================
// 1. معالجة الأوامر (إضافة / تعديل / حذف / استيراد)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    // --- إضافة سؤال جديد ---
    if ($_POST['action'] === 'add') {
        $q = trim($_POST['question']);
        $a = trim($_POST['answer']);
        if ($q && $a) {
            $emb = getEmbedding($q . "\n" . $a);
            if ($emb) {
                $stmt = $db->prepare("INSERT INTO faq (question, answer, embedding) VALUES (?, ?, ?)");
                $stmt->execute([$q, $a, json_encode($emb)]);
                $message = "<div class='alert alert-success'>✅ تمت إضافة السؤال بنجاح.</div>";
            } else {
                $message = "<div class='alert alert-danger'>❌ فشل الاتصال بالذكاء الاصطناعي.</div>";
            }
        }
    }
    
    // --- تعديل سؤال ---
    if ($_POST['action'] === 'edit') {
        $id = (int)$_POST['id'];
        $q = trim($_POST['question']);
        $a = trim($_POST['answer']);
        if ($q && $a) {
            $emb = getEmbedding($q . "\n" . $a);
            if ($emb) {
                $stmt = $db->prepare("UPDATE faq SET question=?, answer=?, embedding=? WHERE id=?");
                $stmt->execute([$q, $a, json_encode($emb), $id]);
                $message = "<div class='alert alert-success'>✅ تم تعديل السؤال بنجاح.</div>";
            } else {
                $message = "<div class='alert alert-danger'>❌ فشل الاتصال بالذكاء الاصطناعي.</div>";
            }
        }
    }

    // --- حذف سؤال ---
    if ($_POST['action'] === 'delete') {
        $id = (int)$_POST['id'];
        $db->prepare("DELETE FROM faq WHERE id=?")->execute([$id]);
        $message = "<div class='alert alert-warning'>🗑️ تم حذف السؤال.</div>";
    }

    // --- استيراد من إكسل ---
    if ($_POST['action'] === 'import' && isset($_FILES['excel_file'])) {
        $file = $_FILES['excel_file']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['excel_file']['name'], PATHINFO_EXTENSION));
        
        $rows = [];
        if ($ext === 'csv') {
            if (($handle = fopen($file, "r")) !== FALSE) {
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    $rows[] = $data;
                }
                fclose($handle);
            }
        } elseif ($ext === 'xlsx') {
            // قارئ إكسل مبسط بدون مكتبات خارجية
            $zip = new ZipArchive;
            if ($zip->open($file) === TRUE) {
                $sharedStrings = [];
                if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                    $xml = simplexml_load_string($zip->getFromName('xl/sharedStrings.xml'));
                    foreach ($xml->si as $val) {
                        $sharedStrings[] = (string)$val->t;
                    }
                }
                $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet1.xml'));
                foreach ($xml->sheetData->row as $row) {
                    $rowData = [];
                    foreach ($row->c as $cell) {
                        $v = (string)$cell->v;
                        if ((string)$cell['t'] === 's') { $v = $sharedStrings[(int)$v] ?? ''; }
                        $rowData[] = $v;
                    }
                    $rows[] = $rowData;
                }
                $zip->close();
            }
        }

        if ($rows) {
            $inserted = 0;
            $stmt = $db->prepare("INSERT INTO faq (question, answer, embedding) VALUES (?, ?, ?)");
            foreach ($rows as $index => $row) {
                // تخطي الصف الأول إذا كان يحتوي على عناوين
                if ($index === 0 && (mb_strpos($row[0], 'سؤال') !== false || mb_strpos($row[0], 'Question') !== false)) continue;
                
                $q = trim($row[0] ?? '');
                $a = trim($row[1] ?? '');
                if ($q && $a) {
                    $emb = getEmbedding($q . "\n" . $a);
                    if ($emb) {
                        $stmt->execute([$q, $a, json_encode($emb)]);
                        $inserted++;
                    }
                    usleep(500000); // انتظار نصف ثانية لتجنب حظر الـ API
                }
            }
            $message = "<div class='alert alert-success'>✅ تم استيراد {$inserted} سؤال بنجاح.</div>";
        } else {
            $message = "<div class='alert alert-danger'>❌ فشل قراءة الملف، تأكد من الصيغة.</div>";
        }
    }
}

// جلب جميع الأسئلة للعرض
$faqs = $db->query("SELECT id, question, answer FROM faq ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>لوحة إدارة البوت</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.rtl.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
    <style>
        body { background-color: #f8f9fa; padding: 20px; font-family: Tahoma, sans-serif; }
        .table th { background-color: #e9ecef; }
        .action-btns form { display: inline; }
        .export-btn { background-color: #198754; color: white; }
    </style>
</head>
<body>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>🤖 لوحة إدارة أسئلة البوت</h2>
        <a href="admin.php" class="btn btn-outline-secondary btn-sm">تحديث الصفحة</a>
    </div>

    <?= $message ?>

    <div class="row mb-4">
        <!-- نموذج الإضافة -->
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">إضافة سؤال وجواب</div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-2">
                            <textarea name="question" class="form-control" rows="2" placeholder="السؤال..." required></textarea>
                        </div>
                        <div class="mb-2">
                            <textarea name="answer" class="form-control" rows="3" placeholder="الجواب..." required></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">إضافة للقاعدة (مع الذكاء الاصطناعي)</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- نموذج الاستيراد -->
        <div class="col-md-6 mb-3">
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">استيراد من ملف إكسل</div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import">
                        <div class="mb-3">
                            <label class="form-label">اختر ملف (.xlsx أو .csv)</label>
                            <input type="file" name="excel_file" class="form-control" accept=".xlsx, .csv" required>
                            <small class="text-muted">العمود الأول: السؤال | العمود الثاني: الجواب</small>
                        </div>
                        <button type="submit" class="btn btn-success w-100">رفع واستيراد</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- جدول الأسئلة -->
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <span>قائمة الأسئلة (<?= count($faqs) ?>)</span>
            <button onclick="exportToExcel()" class="btn btn-sm export-btn">تصدير إلى إكسل (JavaScript)</button>
        </div>
        <div class="card-body table-responsive">
            <table class="table table-bordered table-hover" id="faqTable">
                <thead>
                    <tr>
                        <th width="5%">#</th>
                        <th width="35%">السؤال</th>
                        <th width="45%">الجواب</th>
                        <th width="15%">إجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($faqs as $faq): ?>
                    <tr>
                        <td><?= $faq['id'] ?></td>
                        <td><?= htmlspecialchars($faq['question']) ?></td>
                        <td><?= htmlspecialchars($faq['answer']) ?></td>
                        <td class="action-btns">
                            <!-- زر التعديل -->
                            <button class="btn btn-sm btn-warning" onclick="editFaq(<?= $faq['id'] ?>, '<?= htmlspecialchars(addslashes($faq['question'])) ?>', '<?= htmlspecialchars(addslashes($faq['answer'])) ?>')">تعديل</button>
                            
                            <!-- زر الحذف -->
                            <form method="POST" onsubmit="return confirm('هل أنت متأكد من الحذف؟');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $faq['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-danger">حذف</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- نافذة التعديل المنبثقة (Modal) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">تعديل السؤال</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-2">
                        <label>السؤال</label>
                        <textarea name="question" id="edit_question" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-2">
                        <label>الجواب</label>
                        <textarea name="answer" id="edit_answer" class="form-control" rows="4" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning">حفظ التعديلات</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // دالة فتح نافذة التعديل وتعبئة البيانات
    function editFaq(id, question, answer) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_question').value = question;
        document.getElementById('edit_answer').value = answer;
        var modal = new bootstrap.Modal(document.getElementById('editModal'));
        modal.show();
    }

    // دالة تصدير الإكسل باستخدام JavaScript (SheetJS)
    function exportToExcel() {
        var table = document.getElementById('faqTable');
        var wb = XLSX.utils.table_to_book(table, {sheet: "الأسئلة"});
        XLSX.writeFile(wb, "FAQ_Database_" + new Date().toISOString().slice(0,10) + ".xlsx");
    }
</script>
</body>
</html>
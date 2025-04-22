<?php
include("inc_err.php");
include("server_dwh.php");
include("lib.php");
session_start();
include("auth.php");

set_time_limit(60 * 10);
$uploadby = $_SESSION["reporttools_userid"];
$file_excel = $_FILES["file_excel"]["name"];
$file_ext = strtolower(pathinfo($file_excel, PATHINFO_EXTENSION));

if ($_FILES["file_excel"]["error"] == 0) {
    // Validasi file harus berupa Excel
    if ($file_ext != "xlsx" && $file_ext != "xls") {
        echo "<h3 style='color:red;'>ERROR: File harus berupa Excel (.xlsx atau .xls)</h3>";
        exit();
    }

    $file_excel_tmp = $_FILES["file_excel"]["tmp_name"];
    move_uploaded_file($file_excel_tmp, "uploads/$file_excel");
    save_to_db("uploads/$file_excel");
}

function save_to_db($fname)
{
    global $myconn;
    global $uploadby;
    global $file_excel;
    require_once 'SimpleXLSX.php';

    if ($xlsx = SimpleXLSX::parse($fname)) {
        $arr = $xlsx->rows();
        $n = count($arr);

        $data = [];
        $hashSet = [];

        for ($i = 1; $i < $n; $i++) {
            $PLAN_CODE   = trim($arr[$i][0]);
            $PLAN_NAME   = trim($arr[$i][1]);
            $LINI_USAHA  = trim($arr[$i][2]);
            $OJK_CODE    = trim($arr[$i][3]);

            // Skip jika plan code atau ojk code kosong
            if ($PLAN_CODE == '' || $OJK_CODE == '') continue;

            // Bikin key unik untuk hindari duplikat insert
            $hashkey = $PLAN_CODE . '|' . $OJK_CODE;

            if (isset($hashSet[$hashkey])) continue;

            $hashSet[$hashkey] = true;

            $data[] = "('$PLAN_CODE', '$PLAN_NAME', '$LINI_USAHA', '$OJK_CODE', GETDATE(), '$uploadby')";
        }

        if (!empty($data)) {
            $values = implode(",\n", $data);

            $mergeSQL = "
            MERGE INTO RF_OJK_LINIUSAHA_ACT_DEV_20250422 AS target
            USING (
                VALUES 
                $values
            ) AS source (PLAN_CODE, PLAN_NAME, [LINI USAHA], [OJK CODE], SYSTEM_UPDATEDATE, USERID)
            ON target.PLAN_CODE = source.PLAN_CODE AND target.[OJK CODE] = source.[OJK CODE]
            WHEN MATCHED THEN
                UPDATE SET
                    target.PLAN_NAME       = source.PLAN_NAME,
                    target.[LINI USAHA]    = source.[LINI USAHA],
                    target.SYSTEM_UPDATEDATE = GETDATE(),
                    target.USERID          = '$uploadby'
            WHEN NOT MATCHED THEN
                INSERT (PLAN_CODE, PLAN_NAME, [LINI USAHA], [OJK CODE], SYSTEM_UPDATEDATE, USERID)
                VALUES (source.PLAN_CODE, source.PLAN_NAME, source.[LINI USAHA], source.[OJK CODE], GETDATE(), '$uploadby');";

            odbc_exec($myconn, $mergeSQL) or die(odbc_errormsg($myconn));

            // Panggil prosedur simpan data ke table final
            $strsql6 = "EXEC INSERT_REPORT_OJK_LINIUSAHA_TES";
            odbc_exec($myconn, $strsql6) or die(odbc_errormsg($myconn));

            echo "<h3>UPLOAD DAN UPDATE BERHASIL!</h3>";
        } else {
            echo "<h3 style='color:red;'>Tidak ada data valid untuk diupload.</h3>";
        }
    } else {
        echo "<h3 style='color:red;'>Gagal membaca file Excel: " . SimpleXLSX::parseError() . "</h3>";
    }
}
?>
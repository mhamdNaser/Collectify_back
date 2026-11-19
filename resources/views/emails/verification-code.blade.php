<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:Arial,Helvetica,sans-serif;color:#1c1917;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:28px;">
    <p style="margin:0 0 8px;font-size:15px;">
      {{ $purpose === 'email' ? 'Use this code to confirm your new email address:' : 'Use this code to continue:' }}
    </p>
    <p dir="rtl" style="margin:0 0 20px;font-size:15px;">
      {{ $purpose === 'email' ? 'استخدم هذا الرمز لتأكيد بريدك الإلكتروني الجديد:' : 'استخدم هذا الرمز للمتابعة:' }}
    </p>
    <p style="margin:0 0 20px;font-size:32px;font-weight:bold;letter-spacing:8px;text-align:center;">{{ $code }}</p>
    <p style="margin:0;font-size:13px;color:#57534e;">
      The code expires in 15 minutes. If you did not request it, ignore this email.<br>
      <span dir="rtl">ينتهي الرمز خلال 15 دقيقة. إذا لم تطلبه، تجاهل هذه الرسالة.</span>
    </p>
  </div>
</body>
</html>

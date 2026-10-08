/**
 * 复制文本：优先 Clipboard API，http 等非安全环境自动降级 execCommand
 */
export async function copyText(text: string): Promise<boolean> {
  // 安全上下文（https/localhost）走 Clipboard API
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch {
      /* 降级 */
    }
  }
  // 降级：隐藏 textarea + execCommand（http 环境可用）
  try {
    const ta = document.createElement("textarea");
    ta.value = text;
    ta.style.cssText =
      "position:fixed;left:-9999px;top:-9999px;opacity:0;height:0;";
    ta.setAttribute("readonly", "");
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, text.length);
    const ok = document.execCommand("copy");
    document.body.removeChild(ta);
    return ok;
  } catch {
    return false;
  }
}

/** 复制并提示 */
export async function copyWithTip(text: string, tip = "已复制") {
  const ok = await copyText(text);
  import("@/utils/message").then(({ message }) => {
    ok
      ? message(tip, { type: "success" })
      : message("复制失败，请手动选择复制", { type: "error" });
  });
  return ok;
}

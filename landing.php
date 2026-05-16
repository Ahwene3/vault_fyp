<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) {
    redirect(base_url('dashboard.php'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FYP Vault — Smart Final Year Project Management | Regional Maritime University</title>
<meta name="description" content="FYP Vault is the official Final Year Project management platform for Regional Maritime University, Ghana. Streamlining submissions, supervision, collaboration, and research archiving.">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth;font-size:16px}
body{font-family:'Inter',system-ui,sans-serif;background:#050816;color:#fff;overflow-x:hidden;-webkit-font-smoothing:antialiased}
img{max-width:100%;display:block}
a{text-decoration:none;color:inherit}
button{cursor:pointer;font-family:inherit}
::-webkit-scrollbar{width:6px}
::-webkit-scrollbar-track{background:#050816}
::-webkit-scrollbar-thumb{background:linear-gradient(#2563EB,#22D3EE);border-radius:3px}
:root{
  --bg-deep:#050816;--bg-mid:#081028;--blue:#2563EB;--cyan:#22D3EE;--purple:#6D28D9;
  --text-muted:#94A3B8;--glass-bg:rgba(8,16,40,0.6);--glass-border:rgba(255,255,255,0.08);
}
#stars-canvas{position:fixed;inset:0;z-index:0;pointer-events:none}
.grid-bg{
  position:fixed;inset:0;z-index:0;pointer-events:none;
  background-image:linear-gradient(rgba(148,163,184,0.04) 1px,transparent 1px),
                   linear-gradient(90deg,rgba(148,163,184,0.04) 1px,transparent 1px);
  background-size:72px 72px;
  mask-image:radial-gradient(ellipse at 50% 0%,black 40%,transparent 80%);
}
.orb{position:fixed;border-radius:50%;filter:blur(120px);pointer-events:none;z-index:0}
.orb-1{width:700px;height:700px;top:-200px;left:-200px;background:radial-gradient(circle,rgba(37,99,235,0.18),transparent 70%)}
.orb-2{width:600px;height:600px;top:200px;right:-180px;background:radial-gradient(circle,rgba(109,40,217,0.16),transparent 70%)}
.orb-3{width:500px;height:500px;bottom:0;left:30%;background:radial-gradient(circle,rgba(34,211,238,0.1),transparent 70%)}
.z1{position:relative;z-index:1}
.section{padding:110px 0}
.container{width:100%;padding:0 6vw}
.tag{display:inline-flex;align-items:center;gap:8px;padding:7px 18px;border-radius:999px;border:1px solid rgba(34,211,238,0.25);background:rgba(34,211,238,0.08);font-size:13px;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:#22D3EE;margin-bottom:22px}
.tag .dot{width:7px;height:7px;border-radius:50%;background:#22D3EE;box-shadow:0 0 8px #22D3EE;animation:pulse-dot 2s ease-in-out infinite}
.section-title{font-family:'Space Grotesk',sans-serif;font-size:clamp(2.4rem,3.5vw,3.6rem);font-weight:700;line-height:1.12;letter-spacing:-.02em}
.section-sub{font-size:1.15rem;color:var(--text-muted);line-height:1.75;max-width:640px}
.gradient-text{background:linear-gradient(135deg,#fff 0%,#22D3EE 50%,#6D28D9 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.btn-primary{display:inline-flex;align-items:center;gap:10px;padding:15px 32px;border-radius:12px;background:linear-gradient(135deg,#2563EB,#22D3EE);color:#fff;font-weight:600;font-size:1rem;border:none;transition:transform .2s,box-shadow .2s;box-shadow:0 0 32px rgba(37,99,235,0.35)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 40px rgba(34,211,238,0.4)}
.btn-secondary{display:inline-flex;align-items:center;gap:10px;padding:15px 32px;border-radius:12px;background:transparent;color:#fff;font-weight:600;font-size:1rem;border:1px solid rgba(255,255,255,0.15);transition:border-color .2s,background .2s}
.btn-secondary:hover{border-color:rgba(34,211,238,0.4);background:rgba(34,211,238,0.06)}

/* NAVBAR */
.navbar{position:fixed;top:0;left:0;right:0;z-index:100;padding:16px 0;transition:background .3s,box-shadow .3s,padding .3s}
.navbar.scrolled{background:rgba(5,8,22,0.88);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-bottom:1px solid rgba(255,255,255,0.06);box-shadow:0 4px 40px rgba(0,0,0,0.4);padding:10px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:24px}
.nav-brand{display:flex;align-items:center;gap:12px;flex-shrink:0}
.nav-logo-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,#2563EB,#6D28D9,#22D3EE);display:grid;place-items:center;font-size:18px;box-shadow:0 0 20px rgba(34,211,238,0.25)}
.nav-brand-name{display:block;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.1rem;letter-spacing:.06em;color:#fff;line-height:1.1}
.nav-brand-sub{display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--text-muted)}
.nav-rmu{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted);padding:7px 14px;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.03);white-space:nowrap}
.nav-links{display:flex;align-items:center;gap:2px}
.nav-links a{padding:9px 16px;border-radius:8px;font-size:.95rem;font-weight:500;color:var(--text-muted);transition:color .2s,background .2s;white-space:nowrap}
.nav-links a:hover{color:#fff;background:rgba(255,255,255,0.06)}
.nav-actions{display:flex;align-items:center;gap:10px;flex-shrink:0}
.nav-login{padding:9px 22px;border-radius:10px;font-size:.95rem;font-weight:600;color:#fff;border:1px solid rgba(255,255,255,0.14);background:transparent;transition:border-color .2s,background .2s;white-space:nowrap}
.nav-login:hover{border-color:rgba(34,211,238,0.4);background:rgba(34,211,238,0.07)}
.nav-register{padding:9px 22px;border-radius:10px;font-size:.95rem;font-weight:600;color:#fff;background:linear-gradient(135deg,#2563EB,#22D3EE);border:none;box-shadow:0 0 20px rgba(37,99,235,0.3);transition:transform .2s,box-shadow .2s;white-space:nowrap}
.nav-register:hover{transform:translateY(-1px);box-shadow:0 4px 24px rgba(34,211,238,0.4)}
.nav-hamburger{display:none;flex-direction:column;gap:5px;background:transparent;border:none;padding:8px;border-radius:8px}
.nav-hamburger span{display:block;width:22px;height:2px;background:#fff;border-radius:1px;transition:transform .3s,opacity .3s}
.nav-mobile{display:none;flex-direction:column;gap:4px;padding:16px 0;border-top:1px solid rgba(255,255,255,0.07);margin-top:12px}
.nav-mobile a{padding:10px 14px;border-radius:10px;font-size:.9rem;font-weight:500;color:var(--text-muted)}
.nav-mobile a:hover{color:#fff;background:rgba(255,255,255,0.06)}
.mob-actions{display:flex;gap:10px;padding:10px 14px 4px}
.mob-actions a{flex:1;text-align:center;padding:10px;border-radius:10px;font-weight:600;font-size:.875rem}
.mob-login{border:1px solid rgba(255,255,255,0.14);color:#fff}
.mob-register{background:linear-gradient(135deg,#2563EB,#22D3EE);color:#fff}

/* HERO */
.hero{min-height:100vh;display:flex;align-items:center;padding:120px 0 80px;position:relative;overflow:hidden}
.hero-grid{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
.hero-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 18px;border-radius:999px;border:1px solid rgba(34,211,238,0.2);background:rgba(34,211,238,0.07);font-size:13px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#22D3EE;margin-bottom:28px}
.hero-badge-dot{width:8px;height:8px;border-radius:50%;background:#22D3EE;box-shadow:0 0 10px #22D3EE;animation:pulse-dot 2s ease-in-out infinite}
.hero-title{font-family:'Space Grotesk',sans-serif;font-size:clamp(3.2rem,5.5vw,6rem);font-weight:700;line-height:1.06;letter-spacing:-.03em;margin-bottom:24px}
.hero-title .brand{background:linear-gradient(135deg,#22D3EE,#2563EB);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-sub{font-size:1.2rem;color:var(--text-muted);line-height:1.75;margin-bottom:40px;max-width:540px}
.hero-cta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:52px}
.hero-stats{display:flex;gap:40px;flex-wrap:wrap}
.hero-stat-num{font-family:'Space Grotesk',sans-serif;font-size:1.8rem;font-weight:700;background:linear-gradient(135deg,#fff,#22D3EE);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.hero-stat-label{font-size:.82rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;margin-top:2px}

/* Mockup */
.hero-visual{position:relative;display:flex;align-items:center;justify-content:center}
.mockup-outer{width:100%;max-width:520px;border-radius:20px;background:rgba(8,16,40,0.8);border:1px solid rgba(255,255,255,0.1);box-shadow:0 0 80px rgba(37,99,235,0.2),0 40px 80px rgba(0,0,0,0.4);overflow:hidden;animation:float-card 6s ease-in-out infinite}
.mockup-bar{padding:12px 16px;background:rgba(15,23,42,0.9);border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:8px}
.mockup-dots{display:flex;gap:6px}
.mockup-dots span{width:10px;height:10px;border-radius:50%}
.mockup-dots span:nth-child(1){background:#FF5F56}
.mockup-dots span:nth-child(2){background:#FFBD2E}
.mockup-dots span:nth-child(3){background:#27C93F}
.mockup-url{flex:1;background:rgba(255,255,255,0.04);border-radius:6px;padding:4px 10px;font-size:11px;color:var(--text-muted);font-family:monospace}
.mockup-body{padding:20px}
.mock-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.mock-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:.95rem}
.mock-badge{font-size:10px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(34,211,238,0.15);color:#22D3EE;border:1px solid rgba(34,211,238,0.2)}
.mock-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.mock-card{padding:12px 10px;border-radius:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.06)}
.mock-card-icon{font-size:18px;margin-bottom:6px}
.mock-card-val{font-size:1.1rem;font-weight:700;font-family:'Space Grotesk',sans-serif}
.mock-card-label{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em}
.mock-bar-label{display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:5px}
.mock-progress{height:6px;border-radius:3px;background:rgba(255,255,255,0.06);overflow:hidden;margin-bottom:14px}
.mock-progress-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#2563EB,#22D3EE)}
.mock-list{display:flex;flex-direction:column;gap:8px}
.mock-item{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.05)}
.mock-avatar{width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#2563EB,#6D28D9);display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0}
.mock-name{font-size:11px;font-weight:600}
.mock-desc{font-size:9px;color:var(--text-muted)}
.mock-status{font-size:9px;font-weight:600;padding:2px 8px;border-radius:999px}
.mock-status.green{background:rgba(34,197,94,0.15);color:#4ade80}
.mock-status.blue{background:rgba(37,99,235,0.15);color:#60a5fa}
.mock-status.amber{background:rgba(245,158,11,0.15);color:#fbbf24}
.accent-card{position:absolute;background:rgba(8,16,40,0.88);border:1px solid rgba(255,255,255,0.1);border-radius:14px;padding:12px 16px;backdrop-filter:blur(16px);display:flex;align-items:center;gap:10px;box-shadow:0 8px 32px rgba(0,0,0,0.3)}
.accent-card-1{top:-20px;right:-30px;animation:float-card 5s ease-in-out 1s infinite}
.accent-card-2{bottom:-10px;left:-40px;animation:float-card 5s ease-in-out 2.5s infinite}
.accent-icon{width:36px;height:36px;border-radius:10px;display:grid;place-items:center;font-size:16px}
.accent-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em}
.accent-value{font-size:.9rem;font-weight:700}

/* ABOUT */
.about-grid{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
.about-img{position:relative;border-radius:24px;overflow:hidden;background:linear-gradient(135deg,rgba(37,99,235,0.1),rgba(109,40,217,0.1),rgba(34,211,238,0.08));border:1px solid rgba(255,255,255,0.08);padding:40px;display:flex;flex-direction:column;gap:20px}
.rmu-emblem{width:90px;height:90px;border-radius:50%;background:linear-gradient(135deg,#2563EB,#6D28D9,#22D3EE);display:grid;place-items:center;font-size:36px;box-shadow:0 0 40px rgba(34,211,238,0.3);margin:0 auto}
.rmu-name{text-align:center;font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.1rem}
.rmu-name span{display:block;font-size:.75rem;color:var(--text-muted);font-weight:400;text-transform:uppercase;letter-spacing:.1em;margin-top:2px}
.about-highlights{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.about-highlight{padding:14px;border-radius:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);text-align:center}
.about-highlight-num{font-family:'Space Grotesk',sans-serif;font-size:1.4rem;font-weight:700;background:linear-gradient(135deg,#22D3EE,#2563EB);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.about-highlight-text{font-size:.75rem;color:var(--text-muted);margin-top:2px}
.about-meta{display:flex;flex-wrap:wrap;gap:16px;margin:24px 0}
.about-meta-item{display:flex;align-items:center;gap:8px;font-size:.85rem;color:var(--text-muted)}
.about-meta-item i{color:#22D3EE}
.about-points{display:flex;flex-direction:column;gap:14px;margin-top:28px}
.about-point{display:flex;align-items:flex-start;gap:14px;padding:16px;border-radius:14px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06)}
.about-point-icon{width:40px;height:40px;border-radius:10px;background:linear-gradient(135deg,rgba(37,99,235,0.2),rgba(34,211,238,0.15));border:1px solid rgba(34,211,238,0.2);display:grid;place-items:center;font-size:18px;flex-shrink:0}
.about-point-title{font-weight:600;font-size:.9rem;margin-bottom:2px}
.about-point-desc{font-size:.8rem;color:var(--text-muted);line-height:1.5}

/* FEATURES */
.features-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-top:56px}
.feature-card{padding:28px 22px;border-radius:20px;background:rgba(8,16,40,0.6);border:1px solid rgba(255,255,255,0.07);transition:border-color .3s,transform .3s,box-shadow .3s;position:relative;overflow:hidden}
.feature-card::before{content:'';position:absolute;inset:0;border-radius:20px;background:radial-gradient(circle at 50% 0%,var(--card-glow,rgba(34,211,238,0.08)),transparent 70%);opacity:0;transition:opacity .3s}
.feature-card:hover{border-color:rgba(34,211,238,0.25);transform:translateY(-4px);box-shadow:0 16px 48px rgba(0,0,0,0.3),0 0 40px var(--card-glow,rgba(34,211,238,0.1))}
.feature-card:hover::before{opacity:1}
.feature-icon{width:58px;height:58px;border-radius:16px;background:linear-gradient(135deg,var(--icon-from,#2563EB),var(--icon-to,#22D3EE));display:grid;place-items:center;font-size:26px;margin-bottom:20px;box-shadow:0 0 24px var(--card-glow,rgba(34,211,238,0.25))}
.feature-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.1rem;margin-bottom:12px}
.feature-desc{font-size:.93rem;color:var(--text-muted);line-height:1.7}

/* STATS */
.stats-section{padding:80px 0;background:linear-gradient(135deg,rgba(37,99,235,0.05),rgba(109,40,217,0.05),rgba(34,211,238,0.03));border-top:1px solid rgba(255,255,255,0.06);border-bottom:1px solid rgba(255,255,255,0.06)}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:40px;text-align:center}
.stat-num{font-family:'Space Grotesk',sans-serif;font-size:clamp(3rem,4.5vw,5rem);font-weight:700;line-height:1;background:linear-gradient(135deg,#fff 0%,#22D3EE 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;margin-bottom:12px}
.stat-label{font-size:1rem;color:var(--text-muted);font-weight:500}

/* ROLES */
.roles-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-top:56px}
.role-card{padding:28px 22px;border-radius:20px;background:rgba(8,16,40,0.6);border:1px solid rgba(255,255,255,0.07);transition:border-color .3s,transform .3s}
.role-card:hover{border-color:var(--role-color,rgba(34,211,238,0.3));transform:translateY(-3px)}
.role-icon{width:64px;height:64px;border-radius:18px;background:linear-gradient(135deg,var(--role-from,#2563EB),var(--role-to,#22D3EE));display:grid;place-items:center;font-size:28px;margin-bottom:20px}
.role-title{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.2rem;margin-bottom:16px}
.role-list{display:flex;flex-direction:column;gap:10px}
.role-list li{display:flex;align-items:flex-start;gap:10px;font-size:.93rem;color:var(--text-muted);line-height:1.5;list-style:none}
.role-list li::before{content:'';flex-shrink:0;width:6px;height:6px;border-radius:50%;background:var(--role-color,#22D3EE);margin-top:5px;box-shadow:0 0 6px var(--role-color,#22D3EE)}

/* PREVIEW */
.preview-tabs{display:flex;gap:8px;justify-content:center;margin-bottom:40px;flex-wrap:wrap}
.preview-tab{padding:9px 20px;border-radius:10px;border:1px solid rgba(255,255,255,0.1);background:transparent;color:var(--text-muted);font-size:.85rem;font-weight:600;cursor:pointer;transition:all .2s}
.preview-tab.active,.preview-tab:hover{background:rgba(37,99,235,0.15);border-color:rgba(37,99,235,0.4);color:#fff}
.preview-screen{display:none}
.preview-screen.active{display:block}
.preview-mockup{border-radius:20px;overflow:hidden;background:rgba(8,16,40,0.85);border:1px solid rgba(255,255,255,0.1);box-shadow:0 0 80px rgba(37,99,235,0.15),0 40px 80px rgba(0,0,0,0.4);max-width:900px;margin:0 auto}
.preview-topbar{padding:12px 18px;background:rgba(15,23,42,0.95);border-bottom:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:12px}
.pm-dots{display:flex;gap:5px}
.pm-dots span{width:10px;height:10px;border-radius:50%}
.pm-dots span:nth-child(1){background:#FF5F56}
.pm-dots span:nth-child(2){background:#FFBD2E}
.pm-dots span:nth-child(3){background:#27C93F}
.pm-sidebar-layout{display:grid;grid-template-columns:180px 1fr;min-height:380px}
.pm-sidebar{background:rgba(5,8,22,0.6);border-right:1px solid rgba(255,255,255,0.06);padding:16px 12px;display:flex;flex-direction:column;gap:4px}
.pm-sidebar-item{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;font-size:11px;font-weight:500;color:var(--text-muted);cursor:default}
.pm-sidebar-item.act{background:rgba(37,99,235,0.15);color:#fff}
.pm-sidebar-item i{font-size:14px}
.pm-main{padding:20px;flex:1}
.pm-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
.pm-mini-card{padding:14px;border-radius:12px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07)}
.pm-mini-icon{font-size:18px;margin-bottom:6px}
.pm-mini-val{font-size:1.2rem;font-weight:700;font-family:'Space Grotesk',sans-serif}
.pm-mini-lbl{font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em}
.pm-msg-item{display:flex;align-items:flex-start;gap:10px;padding:10px;border-radius:10px;background:rgba(255,255,255,0.03);margin-bottom:8px}
.pm-msg-av{width:32px;height:32px;border-radius:9px;background:linear-gradient(135deg,#2563EB,#6D28D9);display:grid;place-items:center;font-size:12px;font-weight:700;flex-shrink:0}
.pm-msg-name{font-size:11px;font-weight:600;margin-bottom:3px}
.pm-msg-text{font-size:10px;color:var(--text-muted);line-height:1.4}
.pm-msg-time{font-size:9px;color:var(--text-muted);flex-shrink:0}
.pm-proj-item{padding:12px;border-radius:12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;gap:12px;margin-bottom:10px}
.pm-proj-num{width:28px;height:28px;border-radius:8px;background:rgba(37,99,235,0.2);display:grid;place-items:center;font-size:10px;font-weight:700;color:#60a5fa;flex-shrink:0}
.pm-proj-info{flex:1}
.pm-proj-title{font-size:11px;font-weight:600;margin-bottom:2px}
.pm-proj-dept{font-size:9px;color:var(--text-muted)}
.pm-proj-st{font-size:9px;font-weight:600;padding:3px 8px;border-radius:999px}
.pm-proj-st.approved{background:rgba(34,197,94,0.15);color:#4ade80}
.pm-proj-st.pending{background:rgba(245,158,11,0.15);color:#fbbf24}
.pm-proj-st.review{background:rgba(37,99,235,0.15);color:#60a5fa}
.pm-content{padding:24px}
.pm-chart-bar{display:flex;align-items:flex-end;gap:6px;height:80px;padding:0 4px}
.pm-bar-col{flex:1;border-radius:4px 4px 0 0;background:linear-gradient(180deg,#22D3EE,#2563EB);opacity:.65;transition:opacity .2s;min-width:0}
.pm-bar-col:hover{opacity:1}

/* WHY */
.why-grid{display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center}
.why-points{display:flex;flex-direction:column;gap:16px}
.why-point{display:flex;align-items:flex-start;gap:16px;padding:20px;border-radius:16px;background:rgba(8,16,40,0.5);border:1px solid rgba(255,255,255,0.07);transition:border-color .2s}
.why-point:hover{border-color:rgba(34,211,238,0.2)}
.why-point-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,var(--wp-from,#2563EB),var(--wp-to,#22D3EE));display:grid;place-items:center;font-size:20px;flex-shrink:0}
.why-point-title{font-weight:700;font-size:.95rem;margin-bottom:5px}
.why-point-desc{font-size:.82rem;color:var(--text-muted);line-height:1.6}
.why-visual{border-radius:24px;background:linear-gradient(135deg,rgba(37,99,235,0.1),rgba(109,40,217,0.08),rgba(34,211,238,0.07));border:1px solid rgba(255,255,255,0.08);padding:32px;display:flex;flex-direction:column;gap:16px}
.why-data-row{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
.why-data-card{padding:16px;border-radius:14px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);text-align:center}
.why-data-num{font-family:'Space Grotesk',sans-serif;font-size:1.4rem;font-weight:700;background:linear-gradient(135deg,#22D3EE,#2563EB);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.why-data-lbl{font-size:.7rem;color:var(--text-muted);margin-top:3px;text-transform:uppercase;letter-spacing:.06em}

/* CTA */
.cta-section{padding:80px 0;position:relative;overflow:hidden}
.cta-bg{position:absolute;inset:0;background:linear-gradient(135deg,rgba(37,99,235,0.12),rgba(109,40,217,0.1),rgba(34,211,238,0.07));border-top:1px solid rgba(255,255,255,0.08);border-bottom:1px solid rgba(255,255,255,0.08)}
.cta-inner{text-align:center;position:relative}
.cta-title{font-family:'Space Grotesk',sans-serif;font-size:clamp(2rem,4vw,3rem);font-weight:700;margin-bottom:16px;letter-spacing:-.02em}
.cta-sub{font-size:1rem;color:var(--text-muted);margin-bottom:36px;max-width:520px;margin-left:auto;margin-right:auto;line-height:1.7}
.cta-actions{display:flex;gap:14px;justify-content:center;flex-wrap:wrap}

/* FOOTER */
.footer{padding:64px 0 32px;border-top:1px solid rgba(255,255,255,0.06)}
.footer-grid{display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:48px;margin-bottom:48px}
.footer-brand-icon{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#2563EB,#6D28D9,#22D3EE);display:grid;place-items:center;font-size:20px;margin-bottom:14px;box-shadow:0 0 20px rgba(34,211,238,0.2)}
.footer-brand-name{font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.1rem;letter-spacing:.04em;margin-bottom:4px}
.footer-brand-sub{font-size:.75rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px}
.footer-desc{font-size:.83rem;color:var(--text-muted);line-height:1.7;margin-bottom:20px}
.footer-contact{display:flex;flex-direction:column;gap:8px}
.footer-contact-item{display:flex;align-items:flex-start;gap:8px;font-size:.8rem;color:var(--text-muted)}
.footer-contact-item i{color:#22D3EE;flex-shrink:0;margin-top:1px}
.footer-col-title{font-weight:700;font-size:.85rem;text-transform:uppercase;letter-spacing:.1em;margin-bottom:18px;color:#fff}
.footer-links{display:flex;flex-direction:column;gap:10px}
.footer-links a{font-size:.83rem;color:var(--text-muted);transition:color .2s}
.footer-links a:hover{color:#22D3EE}
.footer-socials{display:flex;gap:10px;margin-top:20px}
.footer-social{width:38px;height:38px;border-radius:10px;border:1px solid rgba(255,255,255,0.1);background:rgba(255,255,255,0.04);display:grid;place-items:center;font-size:16px;color:var(--text-muted);transition:border-color .2s,color .2s,background .2s}
.footer-social:hover{border-color:rgba(34,211,238,0.4);color:#22D3EE;background:rgba(34,211,238,0.08)}
.footer-bottom{padding-top:28px;border-top:1px solid rgba(255,255,255,0.06);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.footer-copy,.footer-dev{font-size:.78rem;color:var(--text-muted)}
.footer-dev span{color:#22D3EE}

/* Animations */
@keyframes pulse-dot{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.8)}}
@keyframes float-card{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
@keyframes fade-up{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
.reveal{opacity:0;transform:translateY(28px);transition:opacity .7s ease,transform .7s ease}
.reveal.visible{opacity:1;transform:translateY(0)}
.reveal-delay-1{transition-delay:.12s}
.reveal-delay-2{transition-delay:.24s}
.reveal-delay-3{transition-delay:.36s}
.reveal-delay-4{transition-delay:.48s}

/* Responsive */
@media(max-width:1200px){
  .features-grid{grid-template-columns:repeat(2,1fr)}
  .roles-grid{grid-template-columns:repeat(2,1fr)}
  .stats-grid{grid-template-columns:repeat(2,1fr);gap:40px}
  .footer-grid{grid-template-columns:1fr 1fr;gap:40px}
}
@media(max-width:900px){
  .hero-grid{grid-template-columns:1fr}
  .hero-visual{display:none}
  .about-grid,.why-grid{grid-template-columns:1fr}
  .nav-links,.nav-actions,.nav-rmu{display:none}
  .nav-hamburger{display:flex}
  .pm-sidebar{display:none}
  .pm-sidebar-layout{grid-template-columns:1fr}
}
@media(max-width:640px){
  .section{padding:72px 0}
  .hero{padding:100px 0 60px}
  .features-grid,.roles-grid{grid-template-columns:1fr}
  .stats-grid{grid-template-columns:repeat(2,1fr)}
  .footer-grid{grid-template-columns:1fr}
  .accent-card-1,.accent-card-2{display:none}
  .pm-grid-3{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>
<canvas id="stars-canvas"></canvas>
<div class="grid-bg"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>

<!-- NAVBAR -->
<nav class="navbar z1" id="navbar">
  <div class="container">
    <div class="nav-inner">
      <a href="#home" class="nav-brand">
        <div class="nav-logo-icon"><i class="bi bi-safe2-fill"></i></div>
        <div>
          <span class="nav-brand-name">FYP VAULT</span>
          <span class="nav-brand-sub">Final Year Project Hub</span>
        </div>
      </a>
      <div class="nav-rmu"><i class="bi bi-building" style="color:#22D3EE;font-size:13px"></i> Regional Maritime University</div>
      <div class="nav-links">
        <a href="#home">Home</a>
        <a href="#about">About RMU</a>
        <a href="#features">Features</a>
        <a href="#roles">User Roles</a>
        <a href="#contact">Contact</a>
      </div>
      <div class="nav-actions">
        <a href="<?= base_url('login.php') ?>" class="nav-login">Login</a>
        <a href="<?= base_url('register.php') ?>" class="nav-register">Register</a>
      </div>
      <button class="nav-hamburger" id="hamburger" aria-label="Toggle menu">
        <span></span><span></span><span></span>
      </button>
    </div>
    <div class="nav-mobile" id="mobileMenu">
      <a href="#home" class="mobile-link">Home</a>
      <a href="#about" class="mobile-link">About RMU</a>
      <a href="#features" class="mobile-link">Features</a>
      <a href="#roles" class="mobile-link">User Roles</a>
      <a href="#contact" class="mobile-link">Contact</a>
      <div class="mob-actions">
        <a href="<?= base_url('login.php') ?>" class="mob-login">Login</a>
        <a href="<?= base_url('register.php') ?>" class="mob-register">Register</a>
      </div>
    </div>
  </div>
</nav>

<!-- HERO -->
<section class="hero z1" id="home">
  <div class="container">
    <div class="hero-grid">
      <div>
        <div class="hero-badge" style="animation:fade-up .6s ease forwards">
          <span class="hero-badge-dot"></span>Now Live · RMU Academic Year 2025/26
        </div>
        <h1 class="hero-title" style="animation:fade-up .7s .1s ease both">
          <span class="brand">FYP Vault</span><br>Smart Final Year<br>Project Management
        </h1>
        <p class="hero-sub" style="animation:fade-up .7s .2s ease both">
          Streamlining project submissions, supervision, collaboration, research archiving, and academic workflow management for Regional Maritime University, Ghana.
        </p>
        <div class="hero-cta" style="animation:fade-up .7s .3s ease both">
          <a href="<?= base_url('register.php') ?>" class="btn-primary">Get Started <i class="bi bi-arrow-right-short" style="font-size:1.1rem"></i></a>
          <a href="#features" class="btn-secondary"><i class="bi bi-grid-3x3-gap" style="font-size:.95rem"></i> Explore Features</a>
        </div>
        <div class="hero-stats" style="animation:fade-up .7s .4s ease both">
          <div>
            <div class="hero-stat-num">1,000+</div>
            <div class="hero-stat-label">Active Students</div>
          </div>
          <div style="border-left:1px solid rgba(255,255,255,0.1);padding-left:32px">
            <div class="hero-stat-num">500+</div>
            <div class="hero-stat-label">Projects Archived</div>
          </div>
          <div style="border-left:1px solid rgba(255,255,255,0.1);padding-left:32px">
            <div class="hero-stat-num">20+</div>
            <div class="hero-stat-label">Departments</div>
          </div>
        </div>
      </div>

      <div class="hero-visual" style="animation:fade-up .8s .2s ease both">
        <div class="accent-card accent-card-1">
          <div class="accent-icon" style="background:rgba(34,197,94,0.15);border:1px solid rgba(34,197,94,0.2);color:#4ade80"><i class="bi bi-check-circle-fill"></i></div>
          <div><div class="accent-label">Project Approved</div><div class="accent-value" style="color:#4ade80">Maritime IoT System</div></div>
        </div>
        <div class="accent-card accent-card-2">
          <div class="accent-icon" style="background:rgba(34,211,238,0.1);border:1px solid rgba(34,211,238,0.2);color:#22D3EE"><i class="bi bi-chat-dots-fill"></i></div>
          <div><div class="accent-label">New Message</div><div class="accent-value">Dr. Mensah replied</div></div>
        </div>
        <div class="mockup-outer">
          <div class="mockup-bar">
            <div class="mockup-dots"><span></span><span></span><span></span></div>
            <div class="mockup-url">fypvault.rmu.edu.gh/dashboard</div>
          </div>
          <div class="mockup-body">
            <div class="mock-header">
              <div class="mock-title">FYP Dashboard</div>
              <div class="mock-badge">AY 2025/26</div>
            </div>
            <div class="mock-cards">
              <div class="mock-card"><div class="mock-card-icon">📁</div><div class="mock-card-val" style="color:#22D3EE">247</div><div class="mock-card-label">Projects</div></div>
              <div class="mock-card"><div class="mock-card-icon">👥</div><div class="mock-card-val" style="color:#a78bfa">38</div><div class="mock-card-label">Supervisors</div></div>
              <div class="mock-card"><div class="mock-card-icon">✅</div><div class="mock-card-val" style="color:#4ade80">182</div><div class="mock-card-label">Approved</div></div>
            </div>
            <div class="mock-bar-label"><span>Submission Progress</span><span style="color:#22D3EE">74%</span></div>
            <div class="mock-progress"><div class="mock-progress-fill" style="width:74%"></div></div>
            <div class="mock-list">
              <div class="mock-item">
                <div class="mock-avatar">KA</div>
                <div style="flex:1"><div class="mock-name">Kwame Asante</div><div class="mock-desc">Smart Port Monitoring System</div></div>
                <div class="mock-status green">Approved</div>
              </div>
              <div class="mock-item">
                <div class="mock-avatar" style="background:linear-gradient(135deg,#6D28D9,#2563EB)">AF</div>
                <div style="flex:1"><div class="mock-name">Abena Frempong</div><div class="mock-desc">Maritime Safety ML Model</div></div>
                <div class="mock-status blue">In Review</div>
              </div>
              <div class="mock-item">
                <div class="mock-avatar" style="background:linear-gradient(135deg,#059669,#0e7490)">JO</div>
                <div style="flex:1"><div class="mock-name">James Osei</div><div class="mock-desc">Vessel Tracking Dashboard</div></div>
                <div class="mock-status amber">Pending</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ABOUT RMU -->
<section class="section z1" id="about">
  <div class="container">
    <div class="about-grid">
      <div class="about-img reveal">
        <div class="rmu-emblem">⚓</div>
        <div class="rmu-name">Regional Maritime University<span>Accra, Ghana · Est. 2007</span></div>
        <div class="about-highlights">
          <div class="about-highlight"><div class="about-highlight-num">18+</div><div class="about-highlight-text">Years of Excellence</div></div>
          <div class="about-highlight"><div class="about-highlight-num">14</div><div class="about-highlight-text">Member States</div></div>
          <div class="about-highlight"><div class="about-highlight-num">6,000+</div><div class="about-highlight-text">Alumni Worldwide</div></div>
          <div class="about-highlight"><div class="about-highlight-num">IMO</div><div class="about-highlight-text">Accredited</div></div>
        </div>
      </div>
      <div class="reveal reveal-delay-1">
        <div class="tag"><span class="dot"></span>About RMU</div>
        <h2 class="section-title">Shaping Africa's<br><span class="gradient-text">Maritime Future</span></h2>
        <div class="about-meta">
          <div class="about-meta-item"><i class="bi bi-geo-alt-fill"></i> La Accra, Ghana</div>
          <div class="about-meta-item"><i class="bi bi-calendar3"></i> Founded 2007</div>
          <div class="about-meta-item"><i class="bi bi-globe2"></i> IMO Accredited</div>
        </div>
        <p class="section-sub">Regional Maritime University (RMU), Accra-Ghana, is an international tertiary institution dedicated to maritime education, research, and professional excellence across West and Central Africa. Established by 14 member states under ECOWAS, RMU leads maritime human resource development for the region.</p>
        <div class="about-points">
          <div class="about-point">
            <div class="about-point-icon">🌊</div>
            <div><div class="about-point-title">Maritime Academic Excellence</div><div class="about-point-desc">Offering BSc, MSc, and professional programs in nautical science, marine engineering, and port management under international standards.</div></div>
          </div>
          <div class="about-point">
            <div class="about-point-icon">🤝</div>
            <div><div class="about-point-title">International Partnerships</div><div class="about-point-desc">Collaborating with IMO, World Maritime University, and leading institutions across Europe, Asia, and the Americas.</div></div>
          </div>
          <div class="about-point">
            <div class="about-point-icon">🔬</div>
            <div><div class="about-point-title">Research & Innovation</div><div class="about-point-desc">Driving applied maritime research — from port logistics optimization to ocean environment monitoring and digital shipping solutions.</div></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FEATURES -->
<section class="section z1" id="features" style="background:linear-gradient(180deg,transparent,rgba(37,99,235,0.04) 50%,transparent)">
  <div class="container">
    <div style="text-align:center">
      <div class="tag reveal" style="display:inline-flex"><span class="dot"></span>Platform Features</div>
      <h2 class="section-title reveal reveal-delay-1">Everything You Need<br><span class="gradient-text">In One Platform</span></h2>
      <p class="section-sub reveal reveal-delay-2" style="margin:16px auto 0;text-align:center">Built specifically for academic workflows at RMU — powerful, intuitive, and always accessible.</p>
    </div>
    <div class="features-grid">
      <div class="feature-card reveal" style="--card-glow:rgba(34,211,238,0.1);--icon-from:#0e7490;--icon-to:#22D3EE">
        <div class="feature-icon"><i class="bi bi-archive-fill"></i></div>
        <div class="feature-title">Project Vault Archive</div>
        <p class="feature-desc">Securely upload, store, and manage final year project documents with version control and structured file organization.</p>
      </div>
      <div class="feature-card reveal reveal-delay-1" style="--card-glow:rgba(37,99,235,0.1);--icon-from:#1d4ed8;--icon-to:#6D28D9">
        <div class="feature-icon"><i class="bi bi-people-fill"></i></div>
        <div class="feature-title">Supervisor Collaboration</div>
        <p class="feature-desc">Real-time collaboration between students and supervisors with document sharing, milestone tracking, and feedback loops.</p>
      </div>
      <div class="feature-card reveal reveal-delay-2" style="--card-glow:rgba(109,40,217,0.1);--icon-from:#6D28D9;--icon-to:#a855f7">
        <div class="feature-icon"><i class="bi bi-mic-fill"></i></div>
        <div class="feature-title">Voice Note Messaging</div>
        <p class="feature-desc">Send and receive voice messages directly within the platform — modern, efficient communication for feedback and ideas.</p>
      </div>
      <div class="feature-card reveal reveal-delay-3" style="--card-glow:rgba(245,158,11,0.1);--icon-from:#b45309;--icon-to:#f59e0b">
        <div class="feature-icon"><i class="bi bi-mortarboard-fill"></i></div>
        <div class="feature-title">Viva Preparation Hub</div>
        <p class="feature-desc">Comprehensive viva management with scheduling, assessor assignment, score tracking, and preparation resources.</p>
      </div>
      <div class="feature-card reveal" style="--card-glow:rgba(34,197,94,0.1);--icon-from:#065f46;--icon-to:#10b981">
        <div class="feature-icon"><i class="bi bi-graph-up-arrow"></i></div>
        <div class="feature-title">Progress Tracking</div>
        <p class="feature-desc">Visual dashboards and timeline views to monitor project milestones, logbook entries, and submission deadlines in real time.</p>
      </div>
      <div class="feature-card reveal reveal-delay-1" style="--card-glow:rgba(234,179,8,0.1);--icon-from:#854d0e;--icon-to:#eab308">
        <div class="feature-icon"><i class="bi bi-bell-fill"></i></div>
        <div class="feature-title">Smart Notifications</div>
        <p class="feature-desc">Intelligent alert system for deadline reminders, supervisor messages, meeting invitations, and project status updates.</p>
      </div>
      <div class="feature-card reveal reveal-delay-2" style="--card-glow:rgba(236,72,153,0.1);--icon-from:#9d174d;--icon-to:#ec4899">
        <div class="feature-icon"><i class="bi bi-megaphone-fill"></i></div>
        <div class="feature-title">Announcement System</div>
        <p class="feature-desc">HODs and administrators broadcast important announcements to targeted groups — departments, cohorts, or university-wide.</p>
      </div>
      <div class="feature-card reveal reveal-delay-3" style="--card-glow:rgba(37,99,235,0.12);--icon-from:#1e3a8a;--icon-to:#2563EB">
        <div class="feature-icon"><i class="bi bi-shield-lock-fill"></i></div>
        <div class="feature-title">Secure Admin Dashboard</div>
        <p class="feature-desc">Full administrative control with role management, audit logging, department oversight, and platform-wide analytics.</p>
      </div>
    </div>
  </div>
</section>

<!-- STATS -->
<section class="stats-section z1" id="stats">
  <div class="container">
    <div class="stats-grid">
      <div class="reveal" style="text-align:center"><div class="stat-num" data-target="1000" data-suffix="+">0</div><div class="stat-label">Students Enrolled</div></div>
      <div class="reveal reveal-delay-1" style="text-align:center"><div class="stat-num" data-target="500" data-suffix="+">0</div><div class="stat-label">Projects Archived</div></div>
      <div class="reveal reveal-delay-2" style="text-align:center"><div class="stat-num" data-target="20" data-suffix="+">0</div><div class="stat-label">Departments</div></div>
      <div class="reveal reveal-delay-3" style="text-align:center"><div class="stat-num" data-target="4" data-suffix="">0</div><div class="stat-label">User Roles</div></div>
    </div>
  </div>
</section>

<!-- USER ROLES -->
<section class="section z1" id="roles">
  <div class="container">
    <div style="text-align:center">
      <div class="tag reveal" style="display:inline-flex"><span class="dot"></span>User Roles</div>
      <h2 class="section-title reveal reveal-delay-1">Built for Every<br><span class="gradient-text">Stakeholder at RMU</span></h2>
      <p class="section-sub reveal reveal-delay-2" style="margin:16px auto 0;text-align:center">FYP Vault is purpose-built for all academic roles — each with a tailored experience and dedicated tools.</p>
    </div>
    <div class="roles-grid">
      <div class="role-card reveal" style="--role-from:#0e7490;--role-to:#22D3EE;--role-color:rgba(34,211,238,0.3)">
        <div class="role-icon"><i class="bi bi-person-fill"></i></div>
        <div class="role-title">Students</div>
        <ul class="role-list">
          <li>Submit and manage FYP projects</li>
          <li>Track milestones and deadlines</li>
          <li>Chat and voice note with supervisors</li>
          <li>Access viva preparation resources</li>
          <li>Maintain a detailed project logbook</li>
          <li>Receive real-time notifications</li>
        </ul>
      </div>
      <div class="role-card reveal reveal-delay-1" style="--role-from:#1d4ed8;--role-to:#6D28D9;--role-color:rgba(109,40,217,0.3)">
        <div class="role-icon"><i class="bi bi-person-workspace"></i></div>
        <div class="role-title">Supervisors</div>
        <ul class="role-list">
          <li>View and manage assigned projects</li>
          <li>Approve or reject topic submissions</li>
          <li>Schedule and manage meetings</li>
          <li>Review logbook entries with feedback</li>
          <li>Score viva assessments</li>
          <li>Message and voice note students</li>
        </ul>
      </div>
      <div class="role-card reveal reveal-delay-2" style="--role-from:#b45309;--role-to:#f59e0b;--role-color:rgba(245,158,11,0.3)">
        <div class="role-icon"><i class="bi bi-building-fill"></i></div>
        <div class="role-title">Head of Department</div>
        <ul class="role-list">
          <li>Oversee all departmental projects</li>
          <li>Assign supervisors to students</li>
          <li>Publish department announcements</li>
          <li>Monitor submission and progress rates</li>
          <li>Access department-level analytics</li>
          <li>Configure viva panels and schedules</li>
        </ul>
      </div>
      <div class="role-card reveal reveal-delay-3" style="--role-from:#1e3a8a;--role-to:#2563EB;--role-color:rgba(37,99,235,0.3)">
        <div class="role-icon"><i class="bi bi-shield-fill-check"></i></div>
        <div class="role-title">Administrators</div>
        <ul class="role-list">
          <li>Manage all user accounts and roles</li>
          <li>View platform-wide analytics</li>
          <li>Configure system settings</li>
          <li>Broadcast university-wide announcements</li>
          <li>Access full audit trail logs</li>
          <li>Archive and manage project records</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- SYSTEM PREVIEW -->
<section class="section z1" id="preview" style="background:linear-gradient(180deg,transparent,rgba(109,40,217,0.04) 50%,transparent)">
  <div class="container">
    <div style="text-align:center">
      <div class="tag reveal" style="display:inline-flex"><span class="dot"></span>System Preview</div>
      <h2 class="section-title reveal reveal-delay-1">See FYP Vault<br><span class="gradient-text">In Action</span></h2>
      <p class="section-sub reveal reveal-delay-2" style="margin:16px auto 32px;text-align:center">A glimpse into the powerful, intuitive interface designed for RMU's academic community.</p>
    </div>
    <div class="preview-tabs reveal">
      <button class="preview-tab active" data-tab="dashboard">Dashboard</button>
      <button class="preview-tab" data-tab="messages">Messages</button>
      <button class="preview-tab" data-tab="projects">Projects</button>
      <button class="preview-tab" data-tab="viva">Viva Hub</button>
      <button class="preview-tab" data-tab="analytics">Analytics</button>
    </div>
    <div class="reveal reveal-delay-1">
      <!-- Dashboard -->
      <div class="preview-screen active" id="tab-dashboard">
        <div class="preview-mockup">
          <div class="preview-topbar">
            <div class="pm-dots"><span></span><span></span><span></span></div>
            <div style="flex:1;background:rgba(255,255,255,0.04);border-radius:5px;padding:3px 10px;font-size:10px;color:var(--text-muted);font-family:monospace">fypvault.rmu.edu.gh/dashboard</div>
          </div>
          <div class="pm-sidebar-layout">
            <div class="pm-sidebar">
              <div class="pm-sidebar-item act"><i class="bi bi-grid-fill"></i> Dashboard</div>
              <div class="pm-sidebar-item"><i class="bi bi-folder2-open"></i> My Project</div>
              <div class="pm-sidebar-item"><i class="bi bi-chat-dots"></i> Messages</div>
              <div class="pm-sidebar-item"><i class="bi bi-calendar3"></i> Meetings</div>
              <div class="pm-sidebar-item"><i class="bi bi-journal-text"></i> Logbook</div>
              <div class="pm-sidebar-item"><i class="bi bi-award"></i> Viva</div>
              <div class="pm-sidebar-item"><i class="bi bi-bell"></i> Notifications</div>
            </div>
            <div class="pm-main">
              <div class="pm-grid-3">
                <div class="pm-mini-card"><div class="pm-mini-icon">📁</div><div class="pm-mini-val">247</div><div class="pm-mini-lbl">Projects</div></div>
                <div class="pm-mini-card"><div class="pm-mini-icon">👥</div><div class="pm-mini-val">38</div><div class="pm-mini-lbl">Supervisors</div></div>
                <div class="pm-mini-card"><div class="pm-mini-icon">✅</div><div class="pm-mini-val">182</div><div class="pm-mini-lbl">Approved</div></div>
              </div>
              <div style="font-size:11px;font-weight:600;margin-bottom:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em">Recent Activity</div>
              <div class="pm-msg-item"><div class="pm-msg-av">KA</div><div style="flex:1"><div class="pm-msg-name">Kwame Asante submitted Chapter 3</div><div class="pm-msg-text">Smart Port Monitoring System — Methodology complete</div></div><div class="pm-msg-time">2m ago</div></div>
              <div class="pm-msg-item"><div class="pm-msg-av" style="background:linear-gradient(135deg,#6D28D9,#2563EB)">DM</div><div style="flex:1"><div class="pm-msg-name">Dr. Mensah approved topic</div><div class="pm-msg-text">Maritime Safety ML Model approved for development</div></div><div class="pm-msg-time">18m ago</div></div>
              <div class="pm-msg-item"><div class="pm-msg-av" style="background:linear-gradient(135deg,#059669,#0e7490)">SY</div><div style="flex:1"><div class="pm-msg-name">System: Viva scheduled</div><div class="pm-msg-text">James Osei — Viva scheduled for 22 May 2026</div></div><div class="pm-msg-time">1h ago</div></div>
            </div>
          </div>
        </div>
      </div>
      <!-- Messages -->
      <div class="preview-screen" id="tab-messages">
        <div class="preview-mockup">
          <div class="preview-topbar"><div class="pm-dots"><span></span><span></span><span></span></div><div style="flex:1;background:rgba(255,255,255,0.04);border-radius:5px;padding:3px 10px;font-size:10px;color:var(--text-muted);font-family:monospace">fypvault.rmu.edu.gh/messages</div></div>
          <div class="pm-sidebar-layout">
            <div class="pm-sidebar">
              <div style="font-size:9px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.08em;padding:0 10px 8px">Conversations</div>
              <div class="pm-sidebar-item act"><i class="bi bi-person-circle"></i> Dr. Mensah</div>
              <div class="pm-sidebar-item"><i class="bi bi-person-circle"></i> Prof. Asante</div>
              <div class="pm-sidebar-item"><i class="bi bi-person-circle"></i> Dr. Boateng</div>
              <div class="pm-sidebar-item"><i class="bi bi-person-circle"></i> HOD Office</div>
            </div>
            <div class="pm-main">
              <div style="display:flex;gap:8px;margin-bottom:10px">
                <div style="width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#2563EB,#6D28D9);display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0">DM</div>
                <div style="background:rgba(255,255,255,0.06);border-radius:12px 12px 12px 2px;padding:10px 14px;max-width:65%"><div style="font-size:11px;line-height:1.5">Please ensure your methodology chapter is properly cited. I've reviewed Chapter 2 and left comments.</div><div style="font-size:9px;color:var(--text-muted);margin-top:5px">10:24 AM</div></div>
              </div>
              <div style="display:flex;gap:8px;justify-content:flex-end;margin-bottom:10px">
                <div style="background:linear-gradient(135deg,rgba(37,99,235,0.35),rgba(34,211,238,0.2));border-radius:12px 12px 2px 12px;padding:10px 14px;max-width:65%"><div style="font-size:11px;line-height:1.5">Thank you Dr. Mensah! I'll update the citations and resubmit by tomorrow morning.</div><div style="font-size:9px;color:rgba(255,255,255,0.5);margin-top:5px">10:31 AM ✓✓</div></div>
              </div>
              <div style="display:flex;gap:8px">
                <div style="width:28px;height:28px;border-radius:8px;background:linear-gradient(135deg,#2563EB,#6D28D9);display:grid;place-items:center;font-size:11px;font-weight:700;flex-shrink:0">DM</div>
                <div style="background:rgba(109,40,217,0.15);border:1px solid rgba(109,40,217,0.2);border-radius:12px 12px 12px 2px;padding:10px 14px;max-width:65%;display:flex;align-items:center;gap:8px"><i class="bi bi-mic-fill" style="color:#a78bfa;font-size:16px"></i><div style="flex:1;background:rgba(255,255,255,0.08);border-radius:4px;height:4px;position:relative"><div style="position:absolute;left:0;top:0;height:100%;width:40%;background:#a78bfa;border-radius:4px"></div></div><div style="font-size:9px;color:var(--text-muted)">0:42</div></div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <!-- Projects -->
      <div class="preview-screen" id="tab-projects">
        <div class="preview-mockup">
          <div class="preview-topbar"><div class="pm-dots"><span></span><span></span><span></span></div><div style="flex:1;background:rgba(255,255,255,0.04);border-radius:5px;padding:3px 10px;font-size:10px;color:var(--text-muted);font-family:monospace">fypvault.rmu.edu.gh/projects</div></div>
          <div class="pm-content">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><div style="font-size:13px;font-weight:700">Project Vault</div><div style="font-size:9px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(34,211,238,0.12);color:#22D3EE;border:1px solid rgba(34,211,238,0.2)">247 Projects</div></div>
            <div class="pm-proj-item"><div class="pm-proj-num">01</div><div class="pm-proj-info"><div class="pm-proj-title">Smart Port Monitoring System Using IoT Sensors</div><div class="pm-proj-dept">Computer Science · Dr. Mensah</div></div><div class="pm-proj-st approved">Approved</div></div>
            <div class="pm-proj-item"><div class="pm-proj-num">02</div><div class="pm-proj-info"><div class="pm-proj-title">Machine Learning for Maritime Safety Prediction</div><div class="pm-proj-dept">Information Technology · Prof. Asante</div></div><div class="pm-proj-st review">In Review</div></div>
            <div class="pm-proj-item"><div class="pm-proj-num">03</div><div class="pm-proj-info"><div class="pm-proj-title">Real-Time Vessel Tracking Dashboard</div><div class="pm-proj-dept">Nautical Science · Dr. Boateng</div></div><div class="pm-proj-st pending">Pending</div></div>
            <div class="pm-proj-item"><div class="pm-proj-num">04</div><div class="pm-proj-info"><div class="pm-proj-title">Ocean Current Analysis using Deep Learning</div><div class="pm-proj-dept">Marine Engineering · Dr. Acheampong</div></div><div class="pm-proj-st approved">Approved</div></div>
          </div>
        </div>
      </div>
      <!-- Viva -->
      <div class="preview-screen" id="tab-viva">
        <div class="preview-mockup">
          <div class="preview-topbar"><div class="pm-dots"><span></span><span></span><span></span></div><div style="flex:1;background:rgba(255,255,255,0.04);border-radius:5px;padding:3px 10px;font-size:10px;color:var(--text-muted);font-family:monospace">fypvault.rmu.edu.gh/viva</div></div>
          <div class="pm-content">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><div style="font-size:13px;font-weight:700">Viva Hub</div><div style="font-size:9px;padding:4px 10px;border-radius:999px;background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);font-weight:600">12 Scheduled</div></div>
            <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);display:flex;align-items:center;gap:12px;margin-bottom:10px">
              <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1d4ed8,#6D28D9);display:grid;place-items:center;font-size:18px;flex-shrink:0">🎓</div>
              <div style="flex:1"><div style="font-size:11px;font-weight:700;margin-bottom:3px">Kwame Asante — Smart Port Monitoring</div><div style="font-size:9px;color:var(--text-muted)">22 May 2026 · 10:00 AM · ICT Block, Room 204</div><div style="margin-top:8px;display:flex;gap:6px"><div style="background:rgba(255,255,255,0.06);border-radius:6px;padding:4px 8px;font-size:9px;color:var(--text-muted)">Assessor: Dr. Mensah</div><div style="background:rgba(255,255,255,0.06);border-radius:6px;padding:4px 8px;font-size:9px;color:var(--text-muted)">Examiner: Prof. Adu</div></div></div>
              <div style="font-size:9px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(34,211,238,0.12);color:#22D3EE;border:1px solid rgba(34,211,238,0.2);white-space:nowrap">Confirmed</div>
            </div>
            <div style="padding:14px;border-radius:12px;background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.07);display:flex;align-items:center;gap:12px">
              <div style="width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#059669,#0e7490);display:grid;place-items:center;font-size:18px;flex-shrink:0">📊</div>
              <div style="flex:1"><div style="font-size:11px;font-weight:700;margin-bottom:3px">Abena Frempong — Maritime Safety ML</div><div style="font-size:9px;color:var(--text-muted)">24 May 2026 · 2:00 PM · Engineering Block B</div><div style="margin-top:8px"><div style="background:rgba(255,255,255,0.06);border-radius:6px;padding:4px 8px;font-size:9px;color:var(--text-muted);display:inline-block">Assessor: Prof. Asante</div></div></div>
              <div style="font-size:9px;font-weight:600;padding:4px 10px;border-radius:999px;background:rgba(245,158,11,0.12);color:#fbbf24;border:1px solid rgba(245,158,11,0.2);white-space:nowrap">Pending</div>
            </div>
          </div>
        </div>
      </div>
      <!-- Analytics -->
      <div class="preview-screen" id="tab-analytics">
        <div class="preview-mockup">
          <div class="preview-topbar"><div class="pm-dots"><span></span><span></span><span></span></div><div style="flex:1;background:rgba(255,255,255,0.04);border-radius:5px;padding:3px 10px;font-size:10px;color:var(--text-muted);font-family:monospace">fypvault.rmu.edu.gh/admin/analytics</div></div>
          <div class="pm-content">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px"><div style="font-size:13px;font-weight:700">Platform Analytics</div><div style="font-size:9px;color:var(--text-muted)">AY 2025/26</div></div>
            <div class="pm-grid-3" style="margin-bottom:20px">
              <div class="pm-mini-card"><div class="pm-mini-icon" style="color:#22D3EE">📈</div><div class="pm-mini-val" style="color:#22D3EE">74%</div><div class="pm-mini-lbl">Submission Rate</div></div>
              <div class="pm-mini-card"><div class="pm-mini-icon" style="color:#a78bfa">⭐</div><div class="pm-mini-val" style="color:#a78bfa">4.7</div><div class="pm-mini-lbl">Avg. Score</div></div>
              <div class="pm-mini-card"><div class="pm-mini-icon" style="color:#4ade80">✅</div><div class="pm-mini-val" style="color:#4ade80">182</div><div class="pm-mini-lbl">Approved</div></div>
            </div>
            <div style="font-size:10px;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Monthly Submissions</div>
            <div class="pm-chart-bar">
              <?php foreach([45,62,55,70,85,72,90,88,65,78,92,80] as $h): ?>
              <div class="pm-bar-col" style="height:<?= round($h * 0.8) ?>%"></div>
              <?php endforeach; ?>
            </div>
            <div style="display:flex;justify-content:space-between;margin-top:4px">
              <?php foreach(['J','F','M','A','M','J','J','A','S','O','N','D'] as $m): ?>
              <div style="font-size:8px;color:var(--text-muted)"><?= $m ?></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- WHY RMU -->
<section class="section z1" id="why">
  <div class="container">
    <div class="why-grid">
      <div>
        <div class="tag reveal"><span class="dot"></span>Why FYP Vault</div>
        <h2 class="section-title reveal reveal-delay-1">Innovation Meets<br><span class="gradient-text">Academic Excellence</span></h2>
        <p class="section-sub reveal reveal-delay-2" style="margin:16px 0 32px">FYP Vault was built to transform how RMU manages its most critical academic process — with technology that matches the institution's maritime leadership legacy.</p>
        <div class="why-points">
          <div class="why-point reveal reveal-delay-1" style="--wp-from:#0e7490;--wp-to:#22D3EE"><div class="why-point-icon"><i class="bi bi-water"></i></div><div><div class="why-point-title">Maritime-Focused Education</div><div class="why-point-desc">Tailored for RMU's unique curriculum — from nautical science to marine engineering and IT departments.</div></div></div>
          <div class="why-point reveal reveal-delay-2" style="--wp-from:#1d4ed8;--wp-to:#6D28D9"><div class="why-point-icon"><i class="bi bi-lightning-charge-fill"></i></div><div><div class="why-point-title">Innovation-Driven Learning</div><div class="why-point-desc">Equipping students and faculty with modern tools to collaborate, communicate, and create without barriers.</div></div></div>
          <div class="why-point reveal reveal-delay-3" style="--wp-from:#065f46;--wp-to:#10b981"><div class="why-point-icon"><i class="bi bi-journal-bookmark-fill"></i></div><div><div class="why-point-title">Research Excellence</div><div class="why-point-desc">Building a permanent, searchable archive of RMU's academic output — preserving knowledge for future generations.</div></div></div>
          <div class="why-point reveal reveal-delay-4" style="--wp-from:#b45309;--wp-to:#f59e0b"><div class="why-point-icon"><i class="bi bi-globe2"></i></div><div><div class="why-point-title">International Collaboration</div><div class="why-point-desc">Supporting RMU's global partnerships by bringing academic workflows to modern digital standards expected worldwide.</div></div></div>
        </div>
      </div>
      <div class="why-visual reveal reveal-delay-2">
        <div style="font-size:64px;text-align:center;margin-bottom:8px;filter:drop-shadow(0 0 20px rgba(34,211,238,0.4))">⚓</div>
        <div style="text-align:center;margin-bottom:16px">
          <div style="font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:1.05rem;margin-bottom:4px">Regional Maritime University</div>
          <div style="font-size:.75rem;color:var(--text-muted)">La, Accra — Ghana · Est. 2007</div>
        </div>
        <div class="why-data-row">
          <div class="why-data-card"><div class="why-data-num">14</div><div class="why-data-lbl">ECOWAS Member States</div></div>
          <div class="why-data-card"><div class="why-data-num">6,000+</div><div class="why-data-lbl">Alumni Globally</div></div>
          <div class="why-data-card"><div class="why-data-num">IMO</div><div class="why-data-lbl">Accredited</div></div>
          <div class="why-data-card"><div class="why-data-num">WMU</div><div class="why-data-lbl">Partner Institution</div></div>
        </div>
        <div style="margin-top:20px;padding:16px;border-radius:14px;background:rgba(37,99,235,0.08);border:1px solid rgba(37,99,235,0.2);text-align:center">
          <div style="font-size:.8rem;color:#60a5fa;line-height:1.6;font-style:italic">"FYP Vault brings RMU's academic project management into the digital age — efficient, transparent, and built for excellence."</div>
          <div style="font-size:.7rem;color:var(--text-muted);margin-top:8px">— FYP Vault Development Team, RMU 2026</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- CTA -->
<section class="cta-section z1">
  <div class="cta-bg"></div>
  <div class="container">
    <div class="cta-inner">
      <div class="tag reveal" style="display:inline-flex"><span class="dot"></span>Get Started Today</div>
      <h2 class="cta-title reveal reveal-delay-1">Ready to Transform Your<br><span class="gradient-text">Final Year Project Journey?</span></h2>
      <p class="cta-sub reveal reveal-delay-2">Join thousands of RMU students, supervisors, and faculty already using FYP Vault to manage, collaborate, and archive academic projects with confidence.</p>
      <div class="cta-actions reveal reveal-delay-3">
        <a href="<?= base_url('register.php') ?>" class="btn-primary" style="padding:15px 32px;font-size:1rem">Create Your Account <i class="bi bi-arrow-right-short" style="font-size:1.2rem"></i></a>
        <a href="<?= base_url('login.php') ?>" class="btn-secondary" style="padding:15px 32px;font-size:1rem"><i class="bi bi-box-arrow-in-right"></i> Sign In</a>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="footer z1" id="contact">
  <div class="container">
    <div class="footer-grid">
      <div>
        <div class="footer-brand-icon"><i class="bi bi-safe2-fill"></i></div>
        <div class="footer-brand-name">FYP VAULT</div>
        <div class="footer-brand-sub">Final Year Project Hub</div>
        <p class="footer-desc">The official Final Year Project management platform for Regional Maritime University, Ghana. Streamlining academic workflows across all departments.</p>
        <div class="footer-contact">
          <div class="footer-contact-item"><i class="bi bi-geo-alt-fill"></i> La, Accra, Ghana · P.O. Box GP 1115</div>
          <div class="footer-contact-item"><i class="bi bi-telephone-fill"></i> +233 302 773 294</div>
          <div class="footer-contact-item"><i class="bi bi-envelope-fill"></i> info@rmu.edu.gh</div>
          <div class="footer-contact-item"><i class="bi bi-globe2"></i> www.rmu.edu.gh</div>
        </div>
        <div class="footer-socials">
          <a class="footer-social" href="#" aria-label="Facebook"><i class="bi bi-facebook"></i></a>
          <a class="footer-social" href="#" aria-label="Twitter"><i class="bi bi-twitter-x"></i></a>
          <a class="footer-social" href="#" aria-label="LinkedIn"><i class="bi bi-linkedin"></i></a>
          <a class="footer-social" href="#" aria-label="YouTube"><i class="bi bi-youtube"></i></a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Platform</div>
        <div class="footer-links">
          <a href="#features">Features</a>
          <a href="#roles">User Roles</a>
          <a href="#preview">System Preview</a>
          <a href="<?= base_url('login.php') ?>">Sign In</a>
          <a href="<?= base_url('register.php') ?>">Register</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Institution</div>
        <div class="footer-links">
          <a href="#about">About RMU</a>
          <a href="#">Academic Programs</a>
          <a href="#">Research</a>
          <a href="#">Admissions</a>
          <a href="#">Campus Life</a>
        </div>
      </div>
      <div>
        <div class="footer-col-title">Support</div>
        <div class="footer-links">
          <a href="#">Help Center</a>
          <a href="#">User Guide</a>
          <a href="#">Privacy Policy</a>
          <a href="#">Terms of Use</a>
          <a href="#contact">Contact IT</a>
        </div>
      </div>
    </div>
    <div class="footer-bottom">
      <div class="footer-copy">© <?= date('Y') ?> Regional Maritime University. All rights reserved.</div>
      <div class="footer-dev">Developed for <span>Regional Maritime University, Ghana</span> · FYP Vault v2.0</div>
    </div>
  </div>
</footer>

<script>
(function(){
/* Stars */
const canvas=document.getElementById('stars-canvas'),ctx=canvas.getContext('2d');
let W,H,stars=[];
function resize(){W=canvas.width=window.innerWidth;H=canvas.height=window.innerHeight}
function initStars(){
  stars=[];
  const n=Math.floor(W*H/8000);
  for(let i=0;i<n;i++) stars.push({x:Math.random()*W,y:Math.random()*H,r:Math.random()*1.3+0.2,a:Math.random(),da:(Math.random()-.5)*.004,speed:Math.random()*.12+.02});
}
function draw(){
  ctx.clearRect(0,0,W,H);
  stars.forEach(s=>{
    s.a+=s.da; if(s.a<.05||s.a>1)s.da*=-1;
    s.y-=s.speed; if(s.y<-5){s.y=H+5;s.x=Math.random()*W}
    ctx.beginPath();ctx.arc(s.x,s.y,s.r,0,Math.PI*2);
    ctx.fillStyle=`rgba(148,163,184,${s.a*.7})`;ctx.fill();
  });
  requestAnimationFrame(draw);
}
resize();initStars();draw();
window.addEventListener('resize',()=>{resize();initStars()});

/* Navbar */
const navbar=document.getElementById('navbar');
window.addEventListener('scroll',()=>navbar.classList.toggle('scrolled',scrollY>40),{passive:true});

/* Hamburger */
const burger=document.getElementById('hamburger'),mob=document.getElementById('mobileMenu');
let open=false;
burger.addEventListener('click',()=>{
  open=!open;mob.style.display=open?'flex':'none';
  const s=burger.querySelectorAll('span');
  if(open){s[0].style.transform='rotate(45deg) translate(5px,5px)';s[1].style.opacity='0';s[2].style.transform='rotate(-45deg) translate(5px,-5px)'}
  else s.forEach(x=>{x.style.transform='';x.style.opacity=''});
});
document.querySelectorAll('.mobile-link').forEach(l=>l.addEventListener('click',()=>{
  open=false;mob.style.display='none';burger.querySelectorAll('span').forEach(x=>{x.style.transform='';x.style.opacity=''});
}));

/* Scroll reveal */
const revObs=new IntersectionObserver(entries=>entries.forEach(e=>{
  if(e.isIntersecting){e.target.classList.add('visible');revObs.unobserve(e.target)}
}),{threshold:.1,rootMargin:'0px 0px -40px 0px'});
document.querySelectorAll('.reveal').forEach(el=>revObs.observe(el));

/* Counters */
const cntObs=new IntersectionObserver(entries=>entries.forEach(e=>{
  if(!e.isIntersecting)return;
  const el=e.target,target=+el.dataset.target,suffix=el.dataset.suffix||'',t0=performance.now();
  (function tick(now){
    const p=Math.min((now-t0)/1800,1),ease=1-Math.pow(1-p,3);
    el.textContent=Math.round(ease*target).toLocaleString()+(p>=1?suffix:'');
    if(p<1)requestAnimationFrame(tick);
  })(t0);
  cntObs.unobserve(el);
}),{threshold:.5});
document.querySelectorAll('.stat-num[data-target]').forEach(el=>cntObs.observe(el));

/* Preview tabs */
document.querySelectorAll('.preview-tab').forEach(tab=>tab.addEventListener('click',()=>{
  document.querySelectorAll('.preview-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.preview-screen').forEach(s=>s.classList.remove('active'));
  tab.classList.add('active');
  const s=document.getElementById('tab-'+tab.dataset.tab);
  if(s)s.classList.add('active');
}));

/* Smooth scroll */
document.querySelectorAll('a[href^="#"]').forEach(a=>a.addEventListener('click',e=>{
  const t=document.querySelector(a.getAttribute('href'));
  if(t){e.preventDefault();t.scrollIntoView({behavior:'smooth',block:'start'})}
}));
})();
</script>
</body>
</html>

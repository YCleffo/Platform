const fs = require("node:fs");
const path = require("node:path");
const os = require("node:os");
const { execFileSync } = require("node:child_process");
const root = path.resolve(__dirname, "../..");
const packageRoot = path.resolve(
    path.dirname(require.resolve("@mermaid-js/mermaid-cli")),
    "..",
);
const cli = path.join(packageRoot, "src/cli.js");
const configDir = fs.mkdtempSync(path.join(os.tmpdir(), "platform-diagrams-"));
const configFile = path.join(configDir, "config.json");
fs.writeFileSync(
    configFile,
    JSON.stringify({
        theme: "neutral",
        fontFamily: "Arial",
        deterministicIds: true,
        securityLevel: "strict",
    }),
);
try {
    const directory = path.join(root, "docs/processes");
    fs.mkdirSync(path.join(directory, "svg"), { recursive: true });
    for (const file of fs
        .readdirSync(directory)
        .filter((f) => f.endsWith(".mmd"))
        .sort()) {
        const input = path.join(directory, file);
        const output = path.join(
            directory,
            "svg",
            file.replace(/\.mmd$/, ".svg"),
        );
        execFileSync(
            process.execPath,
            [
                cli,
                "-i",
                input,
                "-o",
                output,
                "-c",
                configFile,
                "-b",
                "white",
                "--size",
                "1500",
            ],
            { stdio: "inherit" },
        );
        console.log("Rendered", file);
    }
} finally {
    fs.rmSync(configFile);
    fs.rmdirSync(configDir);
}

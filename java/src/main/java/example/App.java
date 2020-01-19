package example;

import java.io.File;
import java.io.IOException;
import java.io.OutputStream;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.Arrays;

import javax.json.Json;
import javax.json.JsonArray;
import javax.json.JsonWriter;
import javax.json.stream.JsonCollectors;
import javax.servlet.ServletException;

import com.aliyun.fc.runtime.Context;
import javax.servlet.http.HttpServletRequest;
import javax.servlet.http.HttpServletResponse;
import javax.servlet.http.Part;

import com.aliyun.fc.runtime.HttpRequestHandler;

public class App implements HttpRequestHandler {

    private static final String UPLOADED_DIR = "/tmp/uploaded";

    @Override
    public void handleRequest(HttpServletRequest request, HttpServletResponse response, Context context)
            throws IOException, ServletException {
        String requestPath = (String) request.getAttribute("FC_REQUEST_PATH");
        String requestURI = (String) request.getAttribute("FC_REQUEST_URI");

        if (requestPath.equals("") && !requestURI.endsWith("/")) {
            response.setStatus(301);
            response.setHeader("Location", requestURI + "/");
        } else if (requestPath.equals("/list") && request.getMethod().equals("GET")) {
            response.setStatus(200);
            response.setHeader("Content-Type", "application/json");

            Files.createDirectories(Paths.get(UPLOADED_DIR));

            JsonArray uploadedFiles = Arrays.stream(new File(UPLOADED_DIR).listFiles(File::isFile)).map(File::getName)
                    .map(Json::createValue).collect(JsonCollectors.toJsonArray());
            JsonWriter jsonWriter = Json.createWriter(response.getOutputStream());
            jsonWriter.write(uploadedFiles);
            jsonWriter.close();
        } else if (requestPath.equals("/upload") && request.getMethod().equals("POST")) {
            Part part = request.getPart("fileContent");
            String filename = part.getSubmittedFileName();
            Files.copy(part.getInputStream(), Paths.get(UPLOADED_DIR, filename));

            response.setStatus(200);
            response.setHeader("Content-Type", "application/json");

            JsonWriter jsonWriter = Json.createWriter(response.getOutputStream());
            jsonWriter.write(Json.createObjectBuilder().add("code", 200).add("msg", "upload success.").build());
            jsonWriter.close();

        } else if (requestPath.equals("/download") && request.getParameter("filename") != null) {
            String filename = request.getParameter("filename");
            response.setStatus(200);
            response.setHeader("Context-Type", "application/octet-stream");
            response.setHeader("Content-Disposition", "attachment; filename=\"" + filename + "\"");

            OutputStream out = response.getOutputStream();
            Files.copy(Paths.get(UPLOADED_DIR, filename), out);
            out.flush();
            out.close();
        } else {
            response.setStatus(200);
            response.setHeader("Content-Type", "text/html");
            OutputStream out = response.getOutputStream();
            Files.copy(Paths.get("index.html"), out);
            out.flush();
            out.close();
        }
    }
}
